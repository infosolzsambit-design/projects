<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmailLogResource;
use App\Models\EmailLog;
use App\Services\EmailResendService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Configuration → Email Logs — every actual email attempt this app has
 * made (see EmailLog's own docblock for why it's one generic table, not
 * one per email kind: answer-sheet-assigned, reassigned, issue-raised,
 * issue-resolved, ...). Deliberately no permission gating — an explicit
 * request, same "no gating yet" shape several other controllers already
 * have (see e.g. TeacherController's own note), just permanent here rather
 * than deferred.
 *
 * Read-only except resend(): nothing here ever edits a *past* attempt — a
 * retry is always a brand new row (see EmailResendService).
 */
class EmailLogController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly EmailResendService $resendService) {}

    /**
     * GET /email-logs — same multi-purpose shape as every other list in
     * this app (search + a handful of ?field= filters + pagination):
     *  - ?search=       matches subject, and the sender's/receiver's name
     *    or email
     *  - ?type=         the tag each mail service stamps (e.g.
     *    answer_sheet_assigned) — see types() below for the live list of
     *    values actually in use
     *  - ?is_sent=yes|no  sent vs failed
     *  - ?receiver_id=
     *  - ?date_from=, ?date_to=  against created_at
     */
    public function index(Request $request): JsonResponse
    {
        $query = EmailLog::query()->with(['sender', 'receiver'])->latest('id');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhereHas('receiver', function ($q2) use ($search) {
                        $q2->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('sender', fn ($q2) => $q2->where('name', 'like', "%{$search}%"));
            });
        }

        if ($type = $request->string('type')->toString()) {
            $query->where('type', $type);
        }

        if ($request->filled('is_sent')) {
            $statusMap = ['yes' => true, 'no' => false];
            $isSent = Str::lower($request->string('is_sent')->toString());

            if (array_key_exists($isSent, $statusMap)) {
                $query->where('is_sent', $statusMap[$isSent]);
            }
        }

        if ($request->filled('receiver_id')) {
            $query->where('receiver_id', $request->integer('receiver_id'));
        }

        if ($dateFrom = $request->string('date_from')->toString()) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->string('date_to')->toString()) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $perPage = max(1, min(
            (int) $request->integer('per_page', (int) config('pagination.default_per_page')),
            (int) config('pagination.max_per_page'),
        ));

        $logs = $query->paginate($perPage);

        return $this->paginated($logs, 'Email logs fetched successfully.', EmailLogResource::collection($logs));
    }

    /**
     * Distinct `type` values actually in use — populates EmailLogsView.vue's
     * own Type filter dropdown without hardcoding the list client-side, so
     * a new mail service adding its own tag shows up here automatically.
     */
    public function types(): JsonResponse
    {
        $types = EmailLog::query()->whereNotNull('type')->distinct()->orderBy('type')->pluck('type');

        return $this->success($types, 'Email log types fetched successfully.');
    }

    /**
     * POST /email-logs/{email_log}/resend — only for a log whose original
     * attempt failed; a successfully-sent email has nothing to retry (and
     * resending it again would just double up a delivery that already
     * happened). subject/body are optional overrides — the admin's own
     * edit in ResendEmailModal.vue, if they made one; left blank (or
     * unchanged), the original subject/body go out exactly as they were.
     */
    public function resend(Request $request, EmailLog $emailLog): JsonResponse
    {
        if ($emailLog->is_sent) {
            return $this->error('This email already sent successfully — nothing to resend.', 422);
        }

        if (! $emailLog->receiver || ! $emailLog->receiver->email) {
            return $this->error('This recipient no longer has a usable email address.', 422);
        }

        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
        ]);

        $newLog = $this->resendService->resend(
            original: $emailLog,
            senderId: $request->user()->id,
            subject: filled($data['subject'] ?? null) ? $data['subject'] : $emailLog->subject,
            body: filled($data['body'] ?? null) ? $data['body'] : $emailLog->body,
        );

        if (! $newLog->is_sent) {
            return $this->error('Could not resend this email: '.($newLog->error_message ?? 'unknown error.'), 422);
        }

        return $this->success(new EmailLogResource($newLog->load(['sender', 'receiver'])), 'Email resent successfully.');
    }
}
