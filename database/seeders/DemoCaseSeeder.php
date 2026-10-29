<?php

namespace Database\Seeders;

use App\Models\Complaint;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ComplaintService;
use App\Services\IncidentService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class DemoCaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Chỉ tạo dữ liệu demo ở local/testing.');
        }
        $this->call(CatalogSeeder::class);
        $owner = User::where('email', 'shop@guiviet.test')->firstOrFail();
        $support = User::where('email', 'support@guiviet.test')->firstOrFail();
        $dispatcher = User::where('email', 'dispatcher@guiviet.test')->firstOrFail();
        $support->warehouses()->syncWithoutDetaching(Warehouse::where('code', 'KHO-HN')->value('id'));
        $incidents = app(IncidentService::class);
        $complaints = app(ComplaintService::class);
        $photo = fn () => new UploadedFile(public_path('images/logistics-warehouse.jpg'), 'demo-kho.jpg', 'image/jpeg', null, true);

        foreach (['open', 'in_review', 'pending_finance', 'resolved'] as $index => $scenario) {
            $token = '00000005-0000-4000-8000-'.str_pad((string) ($index + 1), 12, '0', STR_PAD_LEFT);
            if (Complaint::where('created_by', $owner->id)->where('client_token', $token)->exists()) {
                continue;
            }
            // Mỗi tình huống chạy trọn vẹn; seed lại không đổi hồ sơ đã được demo thao tác.
            DB::transaction(function () use ($owner, $support, $dispatcher, $incidents, $complaints, $photo, $token, $scenario, $index) {
                $orderToken = '00000004-0000-4000-8000-'.str_pad((string) ([1, 3, 7, 2][$index]), 12, '0', STR_PAD_LEFT);
                $order = Order::where('shop_id', $owner->shop->id)->where('client_token', $orderToken)->firstOrFail();
                $complaint = $complaints->create($owner, $order, ['client_token' => $token, 'type' => $scenario === 'resolved' ? 'delay' : 'damaged',
                    'title' => ['Kiểm tra tình trạng bao bì', 'Xác minh hàng hư hỏng khi giao', 'Theo dõi kết luận bồi thường', 'Yêu cầu xác nhận thời gian giao'][$index],
                    'description' => 'Tình huống demo Sprint 5: shop gửi yêu cầu kiểm tra. Ảnh kho chỉ minh họa cho đồ án.'], $photo());
                $complaints->act($support, $complaint, 'assign', ['revision' => 1, 'assigned_to' => $support->id, 'reason' => 'Demo: CSKH tiếp nhận và phối hợp kho xác minh']);
                $complaints->act($support, $complaint->fresh(), 'message', ['revision' => $complaint->fresh()->revision, 'is_internal' => true, 'body' => 'Ghi chú nội bộ demo: đối chiếu phiếu và ảnh trước khi phản hồi shop.']);
                $complaints->act($support, $complaint->fresh(), 'message', ['revision' => $complaint->fresh()->revision, 'is_internal' => false, 'body' => 'CSKH đã tiếp nhận yêu cầu và đang phối hợp kiểm tra.']);
                if ($scenario === 'resolved') {
                    $complaints->act($support, $complaint->fresh(), 'close', ['revision' => $complaint->fresh()->revision, 'resolution' => 'Đã đối chiếu lịch giao và thông báo kết quả để shop theo dõi.']);

                    return;
                }
                $incident = $incidents->create($support, $order->fresh(), ['client_token' => $token, 'type' => 'damaged', 'severity' => $scenario === 'pending_finance' ? 'critical' : 'high',
                    'title' => 'Demo: xác minh bao bì và hàng hóa', 'description' => 'Demo: kiểm tra tình trạng hàng theo phản ánh của shop; ảnh kho minh họa.', 'blocks_dispatch' => true, 'blocks_reconciliation' => true], $photo());
                $incidents->act($support, $incident, 'assign', ['revision' => 1, 'assigned_to' => $support->id, 'reason' => 'Demo: phân công CSKH phối hợp xác minh']);
                $complaints->act($support, $complaint->fresh(), 'link', ['revision' => $complaint->fresh()->revision, 'incident_id' => $incident->id, 'reason' => 'Demo: khiếu nại cần căn cứ xác minh hàng hóa']);
                if ($scenario === 'open') {
                    return;
                }
                $incidents->act($support, $incident->fresh(), 'propose', ['revision' => $incident->fresh()->revision, 'outcome' => 'compensation', 'amount' => 100000,
                    'conclusion' => 'Demo: đề xuất bồi thường 100.000đ theo kết quả xác minh, chờ chứng từ tài chính.', 'reason' => 'Demo: đã đối chiếu căn cứ và đề xuất kết luận']);
                if ($scenario === 'pending_finance') {
                    $incidents->act($dispatcher, $incident->fresh(), 'review', ['revision' => $incident->fresh()->revision, 'decision' => 'approved', 'reason' => 'Demo: người khác duyệt, chưa xác nhận đã chi tiền']);
                    $complaints->act($support, $complaint->fresh(), 'close', ['revision' => $complaint->fresh()->revision, 'resolution' => 'Đã thông báo kết luận bồi thường được duyệt và chuyển bước xử lý tài chính.']);
                }
            });
        }
    }
}
