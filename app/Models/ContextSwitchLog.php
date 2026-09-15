<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContextSwitchLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'from_organization_id', 'to_organization_id', 'ip_address', 'switched_at',
    ];

    protected $casts = ['switched_at' => 'datetime'];
}
