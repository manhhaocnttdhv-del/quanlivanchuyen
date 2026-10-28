<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    protected $fillable = ['code', 'name', 'region', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
