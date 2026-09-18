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
 *
 * Adresler yerel diskte **host'a göre bağıl** (`/storage/…`) üretilir: vitrin hangi alan adından/IP'den açılırsa
 * açılsın görsel aynı origin'den gelir (CSP `img-src 'self'` ve çoklu Host→Website çözümlemesiyle uyumlu; APP_URL
 * farklı olsa da kırık görsel olmaz). Paylaşım/JSON-LD gibi mutlak adres isteyen yerler `absoluteUrl()` kullanır.
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
        return $this->pathUrl($this->path);
    }

    /** Mutlak adres (og:image, JSON-LD, site haritası): bağıl yol istekteki origin ile tamamlanır. */
    public function absoluteUrl(): string
    {
        return self::absolute($this->url());
    }

    public function absoluteUrlFor(int $width): string
    {
        return self::absolute($this->urlFor($width));
    }

    /** Bağıl (`/storage/…`) adresi mutlak yapar; zaten mutlaksa (S3 vb.) dokunmaz. */
    public static function absolute(string $url): string
    {
        return str_starts_with($url, '/') && ! str_starts_with($url, '//') ? url($url) : $url;
    }

    /** Dosyanın adresi: yerel sürücüde yalnız yol bileşeni; uzak diskte (S3) diskin verdiği mutlak adres. */
    private function pathUrl(string $path): string
    {
        $url = Storage::disk($this->disk)->url($path);

        if ((string) config('filesystems.disks.'.$this->disk.'.driver') === 'local') {
            $relative = parse_url($url, PHP_URL_PATH);

            return is_string($relative) && $relative !== '' ? $relative : $url;
        }

        return $url;
    }

    /**
     * srcset: varyantlar + orijinal, genişliğe göre.
     */
    public function srcset(): string
    {
        $entries = [];

        foreach ((array) ($this->variants ?? []) as $v) {
            if (is_array($v) && isset($v['w'], $v['path'])) {
                $entries[(int) $v['w']] = $this->pathUrl((string) $v['path']).' '.(int) $v['w'].'w';
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

        return $best === null ? $this->url() : $this->pathUrl((string) $best['path']);
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
