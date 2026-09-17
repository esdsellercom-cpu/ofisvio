<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Olay × kanal × alıcı grubu kuralı (etkin/pasif). */
class NotificationRule extends Model
{
    protected $fillable = ['event', 'channel', 'recipient_group', 'enabled', 'updated_by'];

    protected $casts = ['enabled' => 'boolean'];
}
