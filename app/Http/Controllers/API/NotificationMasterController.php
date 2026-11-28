<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\NotifyMasterBellIcon;
use Illuminate\Http\Request;

/**
 * One bell API for every audience. The recipient always comes from the auth token
 * (Store / admin / staff / customer), never from the request body.
 */
class NotificationMasterController extends Controller
{
    protected $notifier;

    public function __construct(NotifyMasterBellIcon $notifier)
    {
        $this->notifier = $notifier;
    }

    /** POST /notifications?page=  body: { per_page, unread_only } */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->respond(false, 'Unauthenticated.', [], 401);
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 50);
        $page = $this->notifier->list($user, $perPage, $request->boolean('unread_only'));

        return $this->respond(true, 'Success', [
            'data'         => $page,
            'unread_count' => $this->notifier->unreadCount($user),
        ]);
    }

    /** GET /notifications/unread-count  (cheap, used for polling) */
    public function unreadCount(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->respond(false, 'Unauthenticated.', [], 401);
        }

        return $this->respond(true, 'Success', [
            'unread_count' => $this->notifier->unreadCount($user),
        ]);
    }

    /** POST /notifications/{id}/read */
    public function markRead(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return $this->respond(false, 'Unauthenticated.', [], 401);
        }

        $changed = $this->notifier->markRead($user, (int) $id);

        return $this->respond(true, $changed ? 'Marked as read.' : 'Already read.', [
            'unread_count' => $this->notifier->unreadCount($user),
        ]);
    }

    /** POST /notifications/read-all */
    public function markAllRead(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->respond(false, 'Unauthenticated.', [], 401);
        }

        $this->notifier->markAllRead($user);

        return $this->respond(true, 'All notifications marked as read.', [
            'unread_count' => 0,
        ]);
    }

    private function respond(bool $success, string $message, array $extra = [], int $http = 200)
    {
        return response()->json(array_merge([
            'status'  => ['success' => $success, 'httpStatus' => $http],
            'message' => $message,
        ], $extra), $http);
    }
}