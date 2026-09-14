<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyResetTokenRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * POST /login — accepts email, username, or phone number in "login".
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $login = (string) $request->validated('login');
        $password = (string) $request->validated('password');

        $user = User::where('email', $login)
            ->orWhere('username', $login)
            ->orWhere('phone_no', $login)
            ->first();

        // Never reveal whether the account exists or why it failed —
        // same generic message for "no such user", "wrong password" and
        // "inactive account". The real reason is only in the audit log.
        if (! $user || ! Hash::check($password, $user->password)) {
            $this->auditLog->log(
                event: 'login-failed',
                module: 'Authentication',
                description: "Failed login attempt for \"{$login}\": invalid credentials.",
            );

            return $this->unauthorized('Invalid credentials.');
        }

        if (! $user->is_active) {
            $this->auditLog->log(
                event: 'login-failed',
                module: 'Authentication',
                description: 'Failed login attempt: account is inactive.',
                auditable: $user,
                causer: $user,
            );

            return $this->unauthorized('Invalid credentials.');
        }

        // The login stamp, the new token, and its IP/browser pin (see
        // issueToken()) all need to land together — a token left without
        // its pin would silently skip PinTokenToClient's check entirely
        // (that middleware treats a null ip_address as "nothing to check"),
        // which is a real security gap, not just a cosmetic one.
        $token = DB::transaction(function () use ($user, $request) {
            $user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ])->saveQuietly();

            $token = $this->issueToken($user, $request);

            $this->auditLog->log(
                event: 'login',
                module: 'Authentication',
                description: 'User logged in.',
                auditable: $user,
                causer: $user,
            );

            return $token;
        });

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->withEffectivePermissions($user->load(['roles', 'permissions', 'teacherDetail'])),
        ], 'Login successful.');
    }

    /**
     * POST /refresh — rotates the current session to a brand new token and
     * revokes the old one immediately, extending the session without
     * requiring a fresh login. Called periodically by the frontend (see
     * stores/auth.js) while the token is still valid — a token that never
     * gets refreshed (idle tab, stolen and replayed later) simply expires
     * per sanctum.expiration and stops working.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();

        // Revoking the old token and issuing+pinning the new one must
        // happen together — a failure between the two would otherwise log
        // the user out with no replacement token.
        $token = DB::transaction(function () use ($user, $request) {
            $user->currentAccessToken()->delete();

            $token = $this->issueToken($user, $request);

            $this->auditLog->log(
                event: 'token-refreshed',
                module: 'Authentication',
                description: 'Session token refreshed.',
                auditable: $user,
                causer: $user,
            );

            return $token;
        });

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
        ], 'Token refreshed successfully.');
    }

    /**
     * Creates a token and pins it to the requesting IP/browser (see
     * PinTokenToClient middleware) — a copied token is only usable from the
     * exact context it was issued to. The create and the pin are two
     * separate writes to the same token row; both must land or neither
     * should (an unpinned token isn't just "less secure", PinTokenToClient
     * treats a null ip_address as nothing to check at all). Nests fine
     * inside login()/refresh()'s own outer transaction — Laravel turns a
     * nested DB::transaction() into a savepoint automatically.
     */
    private function issueToken(User $user, Request $request): string
    {
        return DB::transaction(function () use ($user, $request) {
            $result = $user->createToken('api-token');

            $result->accessToken->forceFill([
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ])->save();

            return $result->plainTextToken;
        });
    }

    /**
     * POST /logout — revokes only the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();

        $this->auditLog->log(
            event: 'logout',
            module: 'Authentication',
            description: 'User logged out of the current session.',
            auditable: $user,
            causer: $user,
        );

        return $this->success(null, 'Logged out successfully.');
    }

    /**
     * POST /logout-all — revokes every token belonging to the user.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $revoked = $user->tokens()->count();
        $user->tokens()->delete();

        $this->auditLog->log(
            event: 'logout-all',
            module: 'Authentication',
            description: "User revoked all sessions ({$revoked} token(s)).",
            auditable: $user,
            causer: $user,
        );

        return $this->success(null, 'Logged out from all devices.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success(
            $this->withEffectivePermissions($request->user()->load(['roles', 'permissions', 'teacherDetail'])),
            'Current user retrieved successfully.',
        );
    }

    /**
     * UserResource's own `permissions` field is direct-to-user grants only
     * (Spatie's `permissions` relation) — nearly always empty in this app,
     * since every permission here is actually granted via a role. Frontend
     * show/hide checks (e.g. DashboardView's per-role sections) need the
     * *effective* set instead — direct grants merged with whatever the
     * user's roles carry — so this adds that as `permission_names`, a flat
     * array of names, alongside login()/me()'s existing UserResource shape.
     * UI-only: no route/middleware gating reads this field.
     */
    private function withEffectivePermissions(User $user): array
    {
        $data = (new UserResource($user))->toArray(request());
        $data['permission_names'] = $user->getAllPermissions()->pluck('name')->values();

        // Checked by role ID, never by name (see config/roles.php) — the
        // same rule UserController::forceDestroy()/RoleController::destroy()
        // already follow.
        $isSuperAdmin = $user->hasRole((int) config('roles.super_admin_id'));
        $data['is_super_admin'] = $isSuperAdmin;

        // Gates ProfileView.vue behind a "finish your profile" redirect
        // (see router/index.js's beforeEach) for anyone who isn't Super
        // Admin — scoped to teachers specifically (the only accounts an
        // e-signature is even possible for, see
        // ProfileController::updateEsign()) so a non-teacher, non-super-
        // admin user is never sent to a page that can't accept anything
        // from them.
        $data['profile_completion_required'] = ! $isSuperAdmin
            && $user->teacherDetail
            && empty($user->teacherDetail->esign);

        return $data;
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check((string) $request->validated('current_password'), $user->password)) {
            return $this->error('The current password is incorrect.', 422);
        }

        $currentTokenId = $user->currentAccessToken()->id;

        // If the password save succeeded but revoking the other sessions
        // failed, anyone still holding one of those old tokens would keep
        // working right through a password change meant to lock them out
        // — a real security gap, not just an inconsistency.
        DB::transaction(function () use ($user, $request, $currentTokenId) {
            $user->password = $request->validated('password');
            $user->save();

            // Keep the current session alive, revoke every other one.
            $user->tokens()->where('id', '!=', $currentTokenId)->delete();

            $this->auditLog->log(
                event: 'password-changed',
                module: 'Authentication',
                description: 'User changed their own password.',
                auditable: $user,
                causer: $user,
            );
        });

        return $this->success(null, 'Password changed successfully.');
    }

    /**
     * POST /forgot-password — emails a reset link via the "users" password
     * broker (see config/auth.php and AppServiceProvider::boot(), which
     * points the emailed link at the Vue app's own /reset-password page and
     * brands the mail). Always answers with the same generic message
     * regardless of whether the address is registered — same principle as
     * login()'s single "Invalid credentials." message — so this endpoint
     * can't be used to enumerate accounts. The real outcome is only in the
     * audit log.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = (string) $request->validated('email');

        $status = Password::sendResetLink(['email' => $email]);

        $this->auditLog->log(
            event: 'password-reset-requested',
            module: 'Authentication',
            description: $status === Password::RESET_LINK_SENT
                ? "Password reset link emailed to \"{$email}\"."
                : "Password reset requested for \"{$email}\": {$status}.",
        );

        return $this->success(null, 'If an account exists for that email, a password reset link has been sent.');
    }

    /**
     * POST /reset-password/verify — lets ResetPasswordView.vue check whether
     * the token/email from the emailed link is still good *before* showing
     * the new-password form at all, instead of only finding out after the
     * user fills it in and submits (see Password::tokenExists(), which
     * checks the same hash+expiry as reset() below but never consumes the
     * token). A dead link — reused, expired, or just wrong — always answers
     * with the same {valid:false}, never a 404/422, so this can't be used to
     * enumerate accounts either.
     */
    public function verifyResetToken(VerifyResetTokenRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        $valid = $user && Password::tokenExists($user, (string) $request->validated('token'));

        return $this->success(['valid' => $valid]);
    }

    /**
     * POST /reset-password — completes the flow started by forgotPassword(),
     * validating the emailed token/email pair and setting the new password.
     * Every existing session for the account is revoked (the old password
     * may have been compromised — that's exactly the scenario this flow
     * exists for), mirroring changePassword()'s "keep this session, kill
     * the rest" logic except there's no "this session" here to keep.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            [
                'email' => $request->validated('email'),
                'password' => $request->validated('password'),
                'token' => $request->validated('token'),
            ],
            function (User $user, string $password) {
                // Same reasoning as changePassword() — the new password and
                // revoking every existing session must land together.
                DB::transaction(function () use ($user, $password) {
                    $user->forceFill(['password' => $password])->save();
                    $user->tokens()->delete();
                });

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $this->error($this->resetPasswordErrorMessage($status), 422);
        }

        $this->auditLog->log(
            event: 'password-reset-completed',
            module: 'Authentication',
            description: 'Password reset via emailed link.',
            auditable: User::where('email', $request->validated('email'))->first(),
        );

        return $this->success(null, 'Your password has been reset. Please sign in with your new password.');
    }

    private function resetPasswordErrorMessage(string $status): string
    {
        return match ($status) {
            Password::INVALID_USER => 'We could not find an account for that email.',
            Password::INVALID_TOKEN => 'This password reset link is invalid or has expired. Please request a new one.',
            default => 'Could not reset your password. Please request a new link and try again.',
        };
    }
}
