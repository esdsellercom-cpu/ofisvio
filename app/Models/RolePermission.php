<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{
    protected $fillable = ['role_id', 'permission_id', 'scope', 'requires_jit', 'requires_dual_control', 'notes'];

    protected $casts = [
        'requires_jit' => 'boolean',
        'requires_dual_control' => 'boolean',
    ];
}
