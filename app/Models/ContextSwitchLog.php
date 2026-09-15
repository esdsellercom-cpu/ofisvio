<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContextSwitchLog extends Model
{
    public const PATH_MEMBERSHIP = 'membership';

    public const PATH_STAFF = 'staff';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'from_organization_id', 'to_organization_id', 'entry_path', 'ip_address', 'switched_at',
    ];

    protected $casts = ['switched_at' => 'datetime'];
}
