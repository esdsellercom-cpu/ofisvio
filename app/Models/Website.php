<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Website — §66. organization_id NULL: Ofisvio'nun kendi vitrini.
 *
 * TenantScope TAŞIMAZ (bilinçli): vitrin herkese açıktır ve personel içerik
 * yönetimi tenant context'i olmadan çalışır. Müşteri siteleri (faz 10)
 * gelince erişim ContentService'te organization_id ile süzülür; o fazda
 * BelongsToTenant eklenip varsayılan site için istisna tanımlanacak.
 */
class Website extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'slug', 'domain', 'theme', 'is_default',
        'seo_title_suffix', 'seo_default_description', 'robots_index', 'seo_locale',
        'same_as', 'legal_name', 'nav_links',
        'cache_ttl_seconds', 'http_max_age', 'http_s_maxage',
        'contact_phone', 'contact_email', 'tagline', 'address', 'hero_media_id', 'whatsapp_number', 'business_hours',
    ];

    protected $casts = ['is_default' => 'boolean', 'robots_index' => 'boolean', 'same_as' => 'array', 'nav_links' => 'array', 'business_hours' => 'array'];

    /**
     * Marka/iletişim bilgisi (faz 29): yalnız site alanları (boşsa gösterilmez).
     * phone_href telefon rakamlarından türer.
     *
     * @return array{name: string, legal_name: string, phone: string, phone_href: string, email: string, tagline: string, address: string, whatsapp: string, whatsapp_href: string, hours: array<int, string>}
     */
    public function brand(): array
    {
        // Audit: iletişim/kimlik yalnız veritabanından (SiteBlockSeeder varsayılan siteyi doldurur); config'te yok.
        $phone = (string) ($this->contact_phone ?? '');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return [
            'name' => $this->name,
            'legal_name' => (string) ($this->legal_name ?: $this->name),
            'phone' => $phone,
            'phone_href' => $digits === '' ? '' : 'tel:'.(str_starts_with($digits, '0') ? '+90'.substr($digits, 1) : '+'.$digits),
            'email' => (string) ($this->contact_email ?? ''),
            'tagline' => (string) ($this->tagline ?? ''),
            'address' => (string) ($this->address ?? ''),
            'whatsapp' => (string) ($this->whatsapp_number ?? ''),
            // wa.me yalnız rakam ister; ön yazılı mesaj texts bloğundan (SiteLayoutComposer ekler).
            'whatsapp_href' => $this->whatsapp_number ? 'https://wa.me/'.preg_replace('/\D+/', '', $this->whatsapp_number) : '',
            'hours' => array_values(array_filter(array_map('strval', (array) ($this->business_hours ?? [])))),
        ];
    }

    /** Sitenin mutlak kök adresi: alan adı varsa https ile, yoksa uygulama adresi. */
    public function baseUrl(): string
    {
        return $this->domain ? 'https://'.$this->domain : rtrim((string) config('app.url'), '/');
    }

    /** @return BelongsTo<Media, $this> */
    public function hero(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'hero_media_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Content, $this> */
    public function contents(): HasMany
    {
        return $this->hasMany(Content::class);
    }

    /**
     * @param  Builder<Website>  $query
     * @return Builder<Website>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
