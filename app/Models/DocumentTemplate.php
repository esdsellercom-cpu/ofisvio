<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Belge şablonu (faz 47): tür başına tek satır; alanlar App\Documents\DocumentTemplates tanımından. */
class DocumentTemplate extends Model
{
    protected $fillable = ['kind', 'fields', 'updated_by'];

    protected $casts = ['fields' => 'array'];
}
