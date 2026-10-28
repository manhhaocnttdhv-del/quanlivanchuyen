<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeeVersion extends Model
{
    protected $fillable = ['name', 'active_from', 'active_until'];

    protected $casts = ['active_from' => 'datetime', 'active_until' => 'datetime', 'published_at' => 'datetime'];

    public function rules()
    {
        return $this->hasMany(FeeRule::class);
    }
}
