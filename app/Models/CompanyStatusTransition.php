<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyStatusTransition extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'company_id', 'from_status', 'to_status', 'performed_by', 'reason', 'created_at',
    ];

    protected $casts = [
        'from_status' => CompanyStatus::class,
        'to_status' => CompanyStatus::class,
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
