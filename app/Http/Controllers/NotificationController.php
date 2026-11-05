<?php

namespace App\Http\Controllers;

use App\Http\Requests\NotificationRequest;
use App\Models\BusinessNotification;
use App\Services\NotificationTarget;
use App\Support\NotificationEvents;
use Illuminate\Http\Request;

/** Hộp thư cá nhân; ownership bảo vệ trạng thái đọc, Policy hồ sơ bảo vệ liên kết nghiệp vụ. */
class NotificationController extends Controller
{
    public function index(NotificationRequest $request)
    {
        $filters = $request->validated();
        // Bắt đầu từ quan hệ user, không lấy toàn bộ notifications rồi mới lọc trên giao diện.
        $query = $request->user()->notifications();
        if (($filters['state'] ?? null) === 'unread') {
            $query->whereNull('read_at');
        } elseif (($filters['state'] ?? null) === 'read') {
            $query->whereNotNull('read_at');
        }
        $query->when($filters['category'] ?? null, fn ($q, $category) => $q->where('data->category', $category));

        return view('portal.notifications', ['notifications' => $query->paginate(20)->withQueryString(),
            'categories' => NotificationEvents::CATEGORIES, 'unreadCount' => $request->user()->unreadNotifications()->count()]);
    }

    public function count(Request $request)
    {
        return response()->json(['unread' => $request->user()->unreadNotifications()->count()])->header('Cache-Control', 'private, no-store');
    }

    public function update(NotificationRequest $request, BusinessNotification $notification)
    {
        $this->authorize('update', $notification);
        $notification->update(['read_at' => $request->boolean('read') ? ($notification->read_at ?? now()) : null]);

        return back()->with('status', 'Đã cập nhật trạng thái thông báo.');
    }

    public function readAll(Request $request)
    {
        // Chỉ đánh dấu các thông báo hiện có của chính tài khoản, kể cả ngoài trang/bộ lọc đang xem.
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('status', 'Đã đánh dấu tất cả thông báo hiện có là đã đọc.');
    }

    public function open(Request $request, BusinessNotification $notification, NotificationTarget $targets)
    {
        $this->authorize('view', $notification);
        // GET chỉ mở hồ sơ; thao tác đọc/chưa đọc đi qua PATCH có CSRF riêng.
        $data = $notification->data;
        $target = $targets->find($data['target_type'] ?? '', (int) ($data['target_id'] ?? 0));
        $url = $targets->url($request->user(), $target);
        if (! $url) {
            return redirect()->route('notifications.index')->withErrors(['notification' => 'Chứng từ không còn tồn tại hoặc bạn không còn quyền truy cập.']);
        }

        return redirect()->to($url);
    }
}
