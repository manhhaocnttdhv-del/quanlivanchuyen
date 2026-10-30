<?php

namespace App\Support;

use Carbon\Carbon;

class ReportPeriod
{
    public static function apply($query, string $column, array $filters): void
    {
        // DATETIME hien co theo timezone ung dung; ngay loc la ngay Viet Nam, can tren loai tru.
        $start = Carbon::parse($filters['from'], 'Asia/Ho_Chi_Minh')->startOfDay();
        $end = Carbon::parse($filters['until'], 'Asia/Ho_Chi_Minh')->addDay()->startOfDay();
        $query->where($column, '>=', $start->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'))
            ->where($column, '<', $end->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'));
    }
}
