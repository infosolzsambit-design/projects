<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * POST /login — accepts either email or username in "login".
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $login = (string) $request->validated('login');
        $password = (string) $request->validated('password');

        $user = User::where('email', $login)->orWhere('username', $login)->first();

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

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->load(['roles', 'permissions'])),
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
        $user->currentAccessToken()->delete();

        $token = $this->issueToken($user, $request);

        $this->auditLog->log(
            event: 'token-refreshed',
            module: 'Authentication',
            description: 'Session token refreshed.',
            auditable: $user,
            causer: $user,
        );

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
        ], 'Token refreshed successfully.');
    }

    /**
     * Creates a token and pins it to the requesting IP/browser (see
     * PinTokenToClient middleware) — a copied token is only usable from the
     * exact context it was issued to.
     */
    private function issueToken(User $user, Request $request): string
    {
        $result = $user->createToken('api-token');

        $result->accessToken->forceFill([
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ])->save();

        return $result->plainTextToken;
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
            new UserResource($request->user()->load(['roles', 'permissions'])),
            'Current user retrieved successfully.',
        );
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check((string) $request->validated('current_password'), $user->password)) {
            return $this->error('The current password is incorrect.', 422);
        }

        $currentTokenId = $user->currentAccessToken()->id;

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

        return $this->success(null, 'Password changed successfully.');
    }
}
