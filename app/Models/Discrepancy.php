<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Discrepancy extends Model
{
    public const TYPES = ['damaged' => 'Hư hỏng', 'missing' => 'Thiếu kiện', 'weight' => 'Lệch cân', 'stock_missing' => 'Thiếu khi kiểm kê', 'stock_movement' => 'Biến động sau snapshot', 'stock_extra' => 'Ngoài danh sách kiểm kê'];

    protected $guarded = ['id'];

    protected $casts = ['blocks_dispatch' => 'boolean', 'resolved_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function item()
    {
        return $this->belongsTo(WarehouseItem::class, 'warehouse_item_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
