<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Vitrin görseli — karantina zincirini (MIME → uzantı → sihirli bayt → boyut → ClamAV →
 * sha256) geçmiş, public diske UUID adla yazılmış kayıt (bkz. MediaService). Responsive
 * varyantlar (`variants`: [{w,h,path}]) GD ile yükleme anında üretilir; `srcset()` bunlardan
 * kurulur. Görsel yolları kodda yazılmaz; her <img> bu modelden gelir.
 */
class Media extends Model
{
    public const STATUS_APPROVED = 'approved';

    protected $table = 'media';

    protected $fillable = [
        'website_id', 'uploaded_by', 'disk', 'path', 'original_name', 'mime_type',
        'size_bytes', 'width', 'height', 'checksum_sha256', 'alt', 'title', 'caption', 'variants', 'status',
    ];

    protected $casts = ['size_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer', 'variants' => 'array'];

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * srcset: varyantlar + orijinal, genişliğe göre.
     */
    public function srcset(): string
    {
        $entries = [];

        foreach ((array) ($this->variants ?? []) as $v) {
            if (is_array($v) && isset($v['w'], $v['path'])) {
                $entries[(int) $v['w']] = Storage::disk($this->disk)->url((string) $v['path']).' '.(int) $v['w'].'w';
            }
        }

        $entries[$this->width] = $this->url().' '.$this->width.'w';
        ksort($entries);

        return implode(', ', $entries);
    }

    /** Belirli genişliğe en yakın (≥) varyantın adresi; yoksa orijinal. */
    public function urlFor(int $width): string
    {
        $best = null;

        foreach ((array) ($this->variants ?? []) as $v) {
            if (is_array($v) && isset($v['w'], $v['path']) && (int) $v['w'] >= $width && ($best === null || (int) $v['w'] < (int) $best['w'])) {
                $best = $v;
            }
        }

        return $best === null ? $this->url() : Storage::disk($this->disk)->url((string) $best['path']);
    }

    /** @return array<int, string> silinecek tüm dosya yolları (orijinal + varyantlar) */
    public function allPaths(): array
    {
        $paths = [$this->path];

        foreach ((array) ($this->variants ?? []) as $v) {
            if (is_array($v) && isset($v['path'])) {
                $paths[] = (string) $v['path'];
            }
        }

        return $paths;
    }

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasMany<Content, $this> */
    public function contents(): HasMany
    {
        return $this->hasMany(Content::class, 'cover_media_id');
    }

    /** @return HasMany<Website, $this> */
    public function heroOf(): HasMany
    {
        return $this->hasMany(Website::class, 'hero_media_id');
    }

    /** @return HasMany<LocationMedia, $this> */
    public function locationLinks(): HasMany
    {
        return $this->hasMany(LocationMedia::class);
    }

    /** @return HasMany<Location, $this> */
    public function coverOf(): HasMany
    {
        return $this->hasMany(Location::class, 'cover_media_id');
    }

    /** Herhangi bir yerde kullanılıyor mu (silme koruması)? */
    public function isInUse(): bool
    {
        return $this->contents()->exists() || $this->heroOf()->exists() || $this->locationLinks()->exists() || $this->coverOf()->exists()
            || Content::query()->where('og_media_id', $this->id)->exists() || MemberProfile::query()->where('avatar_media_id', $this->id)->exists()
            || Service::query()->where('cover_media_id', $this->id)->exists() || Space::query()->where('cover_media_id', $this->id)->exists();
    }
}
