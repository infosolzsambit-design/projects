<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The header bell's in-app notifications. Always scoped to the calling
 * user — nobody can read or mark someone else's — so there's no
 * permission gate: every logged-in user has their own. Newest first.
 */
class UserNotificationController extends Controller
{
    use ApiResponse;

    /** GET /user-notifications?page=&per_page=&filter=unread — newest first, plus the unread count. */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 10), 1), 50);

        $page = UserNotification::where('user_id', $request->user()->id)
            ->when($request->input('filter') === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->success([
            'items' => collect($page->items())->map(fn (UserNotification $n) => $this->present($n))->values(),
            'unread_count' => $this->unread($request),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ], 'Notifications fetched successfully.');
    }

    /** GET /user-notifications/unread-count — cheap, polled by the bell badge. */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success(['unread_count' => $this->unread($request)], 'Unread count fetched successfully.');
    }

    /** POST /user-notifications/{id}/read */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $updated = UserNotification::where('user_id', $request->user()->id)
            ->whereKey($id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        if (! $updated && ! UserNotification::where('user_id', $request->user()->id)->whereKey($id)->exists()) {
            return $this->notFound('Notification not found.');
        }

        return $this->success(['unread_count' => $this->unread($request)], 'Notification marked as read.');
    }

    /** POST /user-notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        UserNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->success(['unread_count' => 0], 'All notifications marked as read.');
    }

    private function unread(Request $request): int
    {
        return UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count();
    }

    private function present(UserNotification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'message' => $n->message,
            'link' => $n->link,
            'is_read' => $n->read_at !== null,
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
