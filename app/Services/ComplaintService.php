<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Policies\ComplaintPolicy;
use App\Policies\IncidentPolicy;
use App\Support\CaseRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ComplaintService
{
    public function __construct(private LogisticsLedger $ledger, private ComplaintPolicy $policy, private AuditService $audit, private CaseEvidence $evidence) {}

    public function create(User $actor, Order $order, array $input, ?UploadedFile $file = null): Complaint
    {
        abort_unless($this->policy->createForOrder($actor, $order), 403);
        $data = Validator::make($input, CaseRules::complaint())->validate();
        $hash = hash('sha256', json_encode(['order' => $order->id, 'data' => $data, 'file' => $file ? hash_file('sha256', $file->getRealPath()) : null], JSON_THROW_ON_ERROR));

        return $this->evidence->run($file, fn ($proof) => DB::transaction(function () use ($actor, $order, $data, $hash, $proof) {
            $order = $this->ledger->lock($order);
            abort_unless($this->policy->createForOrder($actor, $order), 403);
            User::lockForUpdate()->findOrFail($actor->id);
            $existing = Complaint::where('created_by', $actor->id)->where('client_token', $data['client_token'])->lockForUpdate()->first();
            if ($existing) {
                $this->ledger->ensure(hash_equals($existing->creation_hash, $hash), 'Mã yêu cầu đã dùng cho nội dung khác.', 'client_token');

                return $existing;
            }
            $complaint = Complaint::create($data + ['code' => 'KN'.Str::upper((string) Str::ulid()), 'order_id' => $order->id, 'shop_id' => $order->shop_id,
                'warehouse_id' => $order->warehouse_id, 'created_by' => $actor->id, 'creation_hash' => $hash, 'due_at' => now()->addHours(config('cases.complaint_sla_hours'))]);
            $message = $complaint->messages()->create(['created_by' => $actor->id, 'type' => 'created', 'is_internal' => false, 'body' => $data['description']]);
            $this->evidence->attach($actor, $message, $proof);
            $this->audit->record($actor, $complaint, 'complaint.created', [], $complaint->toArray(), 'Shop gửi khiếu nại.');

            return $complaint;
        }, 3));
    }

    public function act(User $actor, Complaint $complaint, string $operation, array $input, ?UploadedFile $file = null): void
    {
        $this->ledger->ensure(in_array($operation, ['message', 'assign', 'link', 'close', 'reopen']), 'Thao tác không hợp lệ.');
        $canView = in_array($operation, ['message', 'reopen']);
        abort_unless($canView ? $this->policy->view($actor, $complaint) : $this->policy->manage($actor, $complaint), 403);
        $data = Validator::make($input, CaseRules::complaintAction($operation))->validate();
        $reference = $complaint->order;
        $this->evidence->run($file, fn ($proof) => DB::transaction(function () use ($actor, $complaint, $operation, $data, $reference, $canView, $proof) {
            $this->ledger->lock($reference);
            $complaint = Complaint::lockForUpdate()->findOrFail($complaint->id);
            $staff = $this->policy->manage($actor, $complaint);
            abort_unless($canView ? $this->policy->view($actor, $complaint) : $staff, 403);
            $this->ledger->ensure($complaint->revision === (int) $data['revision'], 'Khiếu nại đã thay đổi. Tải lại trang.', 'revision');
            $before = $complaint->toArray();
            $internal = false;
            $body = '';
            if ($operation === 'reopen') {
                $this->ledger->ensure($complaint->status === 'closed' && $complaint->closed_at->copy()->addDays(config('cases.complaint_reopen_days'))->isFuture(), 'Chỉ mở lại trong 7 ngày sau khi giải quyết.');
                $complaint->status = $complaint->assigned_to ? 'in_progress' : 'open';
                $complaint->closed_at = null;
                $complaint->resolution = null;
                // Giữ hạn SLA ban đầu để không che thời gian đã quá hạn bằng thao tác mở lại.
                $body = 'Mở lại khiếu nại: '.$data['reason'];
            } else {
                $this->ledger->ensure($complaint->status !== 'closed', 'Khiếu nại đã giải quyết. Mở lại trước khi trao đổi.');
                if ($operation === 'message') {
                    abort_if(! $staff && (bool) $data['is_internal'], 403);
                    $internal = (bool) $data['is_internal'];
                    $body = $data['body'];
                    if ($staff && ! $internal && ! $complaint->first_response_at) {
                        $complaint->first_response_at = now();
                    }
                } elseif ($operation === 'assign') {
                    $target = User::findOrFail($data['assigned_to']);
                    $this->ledger->ensure($this->policy->manage($target, $complaint), 'Người xử lý phải có quyền CSKH và thuộc đúng kho.', 'assigned_to');
                    $complaint->assigned_to = $target->id;
                    $complaint->status = 'in_progress';
                    $body = 'Đã phân công nhân viên tiếp nhận và xử lý.';
                } elseif ($operation === 'link') {
                    $incident = Incident::lockForUpdate()->findOrFail($data['incident_id']);
                    abort_unless(app(IncidentPolicy::class)->view($actor, $incident), 403);
                    $this->ledger->ensure($incident->order_id === $complaint->order_id && $incident->warehouse_id === $complaint->warehouse_id, 'Sự cố phải thuộc đúng đơn và kho.', 'incident_id');
                    $this->ledger->ensure(! $complaint->incident_id, 'Đã liên kết sự cố; không được ghi đè căn cứ.');
                    $complaint->incident_id = $incident->id;
                    $body = 'Đã liên kết hồ sơ xác minh sự cố cho khiếu nại.';
                } elseif ($operation === 'close') {
                    $incident = $complaint->incident()->lockForUpdate()->first();
                    $this->ledger->ensure(! in_array($complaint->type, Complaint::NEEDS_INCIDENT) || $incident, 'Khiếu nại tác động hàng/tiền phải liên kết sự cố trước khi đóng.', 'resolution');
                    $this->ledger->ensure(! $incident || ($incident->status === 'closed' && $incident->approved_action_id), 'Sự cố liên quan cần kết luận được duyệt trước.', 'resolution');
                    $complaint->status = 'closed';
                    $complaint->resolution = $data['resolution'];
                    if ($incident?->financial_status === 'pending_finance') {
                        $complaint->resolution .= "\nKhoản đề xuất đã được duyệt đang chờ xử lý tài chính; chưa xác nhận chi tiền hoặc điều chỉnh.";
                    }
                    $complaint->closed_at = now();
                    if (! $complaint->first_response_at) {
                        $complaint->first_response_at = now();
                    }
                    $body = 'Kết quả xử lý: '.$complaint->resolution;
                }
            }
            $message = $complaint->messages()->create(['created_by' => $actor->id, 'type' => $operation, 'body' => $body, 'is_internal' => $internal]);
            if (in_array($operation, ['assign', 'link'])) {
                // Can cu phan cong/lien ket va tep di kem chi danh cho noi bo.
                $message = $complaint->messages()->create(['created_by' => $actor->id, 'type' => 'note', 'body' => $data['reason'], 'is_internal' => true]);
            }
            $this->evidence->attach($actor, $message, $proof);
            $complaint->revision++;
            $complaint->save();
            $this->audit->record($actor, $complaint, 'complaint.'.$operation, $before, $complaint->toArray(), $data['reason'] ?? 'Cập nhật hồ sơ khiếu nại.');
        }, 3));
    }
}
