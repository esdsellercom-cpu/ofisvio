<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Şablon (DB'de düzenlenir; yoksa NotificationEvents teknik varsayılanı). Yer tutucu: {{ad}}. */
class NotificationTemplate extends Model
{
    protected $fillable = ['event', 'channel', 'locale', 'subject', 'body', 'updated_by'];
}
