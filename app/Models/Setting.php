<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ayar değeri (tanım SettingsRegistry'de). Yalnız SettingsService yazar. */
class Setting extends Model
{
    protected $fillable = ['key', 'scope', 'scope_id', 'value', 'updated_by'];

    protected $casts = ['value' => 'array'];
}
