<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCollection;
use App\Models\Incident;
use App\Models\Order;
use App\Models\OrderChangeRequest;
use App\Models\Receivable;
use Carbon\Carbon;

class AuditSearch
{
    public function query(array $filters)
    {
        $query = AuditLog::with('actor');
        foreach (['actor_id', 'event', 'subject_id', 'request_id'] as $field) {
            $query->when($filters[$field] ?? null, fn ($q, $value) => $q->where($field, $value));
        }
        $query->when($filters['subject_type'] ?? null, fn ($q, $value) => $q->where('subject_type', 'App\\Models\\'.$value));
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'], 'Asia/Ho_Chi_Minh')->startOfDay()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'));
        }
        if (! empty($filters['until'])) {
            $query->where('created_at', '<', Carbon::parse($filters['until'], 'Asia/Ho_Chi_Minh')->addDay()->startOfDay()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'));
        }
        if (! empty($filters['q'])) {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']);
            $orders = Order::where('tracking_code', $filters['q'])->select('id');
            $query->where(function ($q) use ($filters, $search, $orders) {
                $q->where('event', 'like', $search.'%');
                if (ctype_digit($filters['q'])) {
                    $q->orWhere('subject_id', $filters['q']);
                }
                $q->orWhere(fn ($q) => $q->where('subject_type', Order::class)->whereIn('subject_id', clone $orders));
                foreach ([OrderChangeRequest::class, DeliveryAttempt::class, Incident::class, DeliveryCollection::class, Receivable::class] as $model) {
                    $q->orWhere(fn ($q) => $q->where('subject_type', $model)->whereIn('subject_id', $model::whereIn('order_id', clone $orders)->select('id')));
                }
            });
        }

        return $query->latest('id');
    }
}
