<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeeRule extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['base_fee' => 'decimal:2', 'step_fee' => 'decimal:2', 'max_cod' => 'decimal:2'];
}
