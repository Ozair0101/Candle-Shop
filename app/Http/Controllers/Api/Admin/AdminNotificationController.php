<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AdminNotification;
use Illuminate\Http\Request;

class AdminNotificationController extends ApiController
{
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $onlyUnread = filter_var($request->get('unread_only', 'false'), FILTER_VALIDATE_BOOLEAN);

        $query = AdminNotification::query()->orderByDesc('created_at');

        if ($onlyUnread) {
            $query->where('is_read', false);
        }

        $notifications = $query->limit($perPage)->get();

        return $this->success($notifications, 'Notifications retrieved successfully');
    }

    public function markAsRead($id)
    {
        $notification = AdminNotification::find($id);

        if (!$notification) {
            return $this->error('Notification not found', 404);
        }

        $notification->is_read = true;
        $notification->save();

        return $this->success($notification, 'Notification marked as read');
    }

    public function markAllAsRead()
    {
        AdminNotification::where('is_read', false)->update(['is_read' => true]);

        return $this->success(null, 'All notifications marked as read');
    }
}
