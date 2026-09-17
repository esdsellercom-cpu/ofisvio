<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use App\Models\Website;
use App\Security\MalwareScanner;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Medya kütüphanesi — karantina zinciri (faz 3):
 *
 *   Quarantine (private disk, kamuya kapalı) → MIME (içerikten, finfo) → uzantı (MIME'dan türer,
 *   istemci adına bakılmaz) → sihirli bayt (getimagesize + MIME eşleşmesi) → boyut → ClamAV
 *   (tarayıcı yoksa REDDEDİLİR, enfekte dosya asla public'e çıkmaz) → sha256 (yinelenen
 *   engeli) → Approved → public diske UUID adla + responsive varyantlar (GD) → karantina temizlenir.
 *
 * Zincirin herhangi bir halkası düşerse dosya karantinadan silinir ve hiçbir kayıt yazılmaz.
 * Denetim: her onaylı yükleme `media.uploaded`, her red `media.rejected` (aşama adıyla).
 */
class MediaService
{
    public const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Responsive varyant genişlikleri (px); orijinalden küçük olanlar üretilir. */
    public const VARIANT_WIDTHS = [480, 960, 1600];

    public function __construct(private readonly MalwareScanner $scanner, private readonly AuditService $audit) {}

    /** @return LengthAwarePaginator<int, Media> */
    public function paginate(Website $website, int $perPage = 40): LengthAwarePaginator
    {
        return Media::query()->with('uploader')->where('website_id', $website->id)->orderByDesc('created_at')->paginate($perPage)->withQueryString();
    }

    /** Site sınırı: medya bu siteye mi ait? */
    public function belongsTo(Website $website, int $mediaId): bool
    {
        return Media::query()->where('website_id', $website->id)->whereKey($mediaId)->exists();
    }

    /** @return Collection<int, Media> */
    public function all(Website $website): Collection
    {
        return Media::query()->where('website_id', $website->id)->orderByDesc('created_at')->get();
    }

    /**
     * Karantina zinciri. $meta: alt/title/caption.
     *
     * @param  array{alt?: string|null, title?: string|null, caption?: string|null, seo_name?: string|null}  $meta
     */
    public function upload(User $uploader, Website $website, UploadedFile $file, array|string|null $meta = null): Media
    {
        $meta = is_array($meta) ? $meta : ($meta === null ? [] : ['alt' => $meta]);

        // 1) Karantina: yükleme önce kamuya kapalı diske alınır; tüm kontroller bu kopya üzerinde yapılır.
        $quarantine = $file->storeAs('quarantine/media', Str::uuid()->toString().'.bin', 'private');

        if ($quarantine === false) {
            throw new DomainException('Dosya karantinaya alınamadı.');
        }

        $path = Storage::disk('private')->path($quarantine);

        try {
            // 2) MIME içerikten.
            $mime = (string) (mime_content_type($path) ?: '');

            if (! in_array($mime, self::ALLOWED_MIME, true)) {
                $this->reject($uploader, 'mime', $file, $mime);
            }

            // 3) Uzantı MIME'dan türer (istemci adındaki uzantı yok sayılır; .php.jpg gibi oyunlar boşa düşer).
            $extension = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                default => 'webp',
            };

            // 4) Sihirli bayt: gerçek bir raster görsel mi ve türü MIME ile tutarlı mı?
            $dimensions = @getimagesize($path);
            $magicMime = $dimensions !== false ? (string) $dimensions['mime'] : '';

            if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1 || $magicMime !== $mime) {
                $this->reject($uploader, 'magic_bytes', $file, $magicMime);
            }

            // 5) Boyut.
            $size = (int) filesize($path);

            if ($size > self::MAX_BYTES || $size < 1) {
                $this->reject($uploader, 'size', $file, (string) $size);
            }

            // 6) ClamAV — fail-closed.
            $scan = $this->scanner->scan($path);

            if (! $scan->available) {
                $this->reject($uploader, 'scanner_unavailable', $file, (string) $scan->signature, 'Güvenlik taraması yapılamadı; yükleme kabul edilmedi. Daha sonra yeniden deneyin.');
            }

            if ($scan->isInfected()) {
                $this->reject($uploader, 'infected', $file, (string) $scan->signature, 'Güvenlik taraması dosyada zararlı içerik buldu ('.$scan->signature.'). Dosya kaydedilmedi.');
            }

            // 7) sha256: aynı sitede aynı içerik ikinci kez yüklenirse mevcut kayıt döner.
            $hash = hash_file('sha256', $path) ?: '';
            $existing = Media::query()->where('website_id', $website->id)->where('checksum_sha256', $hash)->first();

            if ($existing !== null) {
                return $existing; // yinelenen içerik: mevcut kayıt ve meta korunur
            }

            // 8) Approved → public disk + varyantlar.
            $uuid = Str::uuid()->toString();
            $target = 'media/'.$website->id.'/'.$uuid.'.'.$extension;

            if (! Storage::disk('public')->put($target, (string) file_get_contents($path))) {
                throw new DomainException('Görsel diske yazılamadı.');
            }

            $variants = $this->makeVariants($path, $mime, (int) $dimensions[0], (int) $dimensions[1], 'media/'.$website->id.'/'.$uuid, $extension);

            $media = DB::transaction(fn () => Media::create([
                'website_id' => $website->id,
                'uploaded_by' => $uploader->id,
                'disk' => 'public',
                'path' => $target,
                // SEO uyumlu dosya adı (faz 48): verilmişse istemci adı yerine slug + gerçek uzantı.
                'original_name' => ! empty($meta['seo_name']) ? Str::slug((string) $meta['seo_name']).'.'.$extension : mb_substr($file->getClientOriginalName(), 0, 190),
                'mime_type' => $mime,
                'size_bytes' => $size,
                'width' => (int) $dimensions[0],
                'height' => (int) $dimensions[1],
                'checksum_sha256' => $hash,
                'alt' => $this->clean($meta['alt'] ?? null, 190),
                'title' => $this->clean($meta['title'] ?? null, 160),
                'caption' => $this->clean($meta['caption'] ?? null, 300),
                'variants' => $variants,
                'status' => Media::STATUS_APPROVED,
            ]));

            $this->audit->record($uploader, 'media.uploaded', 'media', $media->id, [], ['path' => $target, 'mime' => $mime, 'size' => $size, 'variants' => count($variants), 'sha256' => $hash]);

            return $media;
        } finally {
            // Karantina her sonuçta temizlenir; onaylı kopya artık public'te.
            Storage::disk('private')->delete($quarantine);
        }
    }

    /**
     * Kırpma/istemci üretimi görsel (faz 48): data URL geçici dosyaya yazılır ve aynı karantina zincirinden geçer.
     *
     * @param  array{alt?: string|null, title?: string|null, caption?: string|null, seo_name?: string|null}  $meta
     */
    public function uploadDataUrl(User $uploader, Website $website, string $dataUrl, array $meta = []): Media
    {
        if (preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', $dataUrl, $m) !== 1) {
            throw new DomainException('Geçersiz görsel verisi.');
        }

        $bytes = base64_decode($m[2], true);

        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new DomainException('Görsel verisi çözülemedi ya da çok büyük.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ofisvio-crop-');

        if ($tmp === false) {
            throw new DomainException('Geçici dosya oluşturulamadı.');
        }

        file_put_contents($tmp, $bytes);

        try {
            $name = (Str::slug((string) ($meta['seo_name'] ?? 'kirpilmis')) ?: 'kirpilmis').'.'.($m[1] === 'jpeg' ? 'jpg' : $m[1]);

            return $this->upload($uploader, $website, new UploadedFile($tmp, $name, 'image/'.$m[1], null, true), $meta);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @param  array{alt?: string|null, title?: string|null, caption?: string|null}  $meta
     */
    public function applyMeta(Media $media, array $meta, ?User $actor = null): Media
    {
        $before = $media->only(['alt', 'title', 'caption']);
        $media->fill([
            'alt' => array_key_exists('alt', $meta) ? $this->clean($meta['alt'], 190) : $media->alt,
            'title' => array_key_exists('title', $meta) ? $this->clean($meta['title'], 160) : $media->title,
            'caption' => array_key_exists('caption', $meta) ? $this->clean($meta['caption'], 300) : $media->caption,
        ]);

        if ($media->isDirty()) {
            $media->save();
            $this->audit->record($actor, 'media.meta_updated', 'media', $media->id, $before, $media->only(['alt', 'title', 'caption']));
        }

        return $media;
    }

    public function updateAlt(Media $media, ?string $alt): Media
    {
        return $this->applyMeta($media, ['alt' => $alt]);
    }

    /** Silme: kullanan içerik/site/lokasyon varsa reddedilir (kırık görsel bırakmaz). Dosya ve varyantlar da silinir. */
    public function delete(Media $media, ?User $actor = null): void
    {
        if ($media->isInUse()) {
            throw new DomainException('Bu görsel kullanımda (kapak, hero ya da lokasyon galerisi); önce oradan kaldırın.');
        }

        Storage::disk($media->disk)->delete($media->allPaths());
        $media->delete();
        $this->audit->record($actor, 'media.deleted', 'media', $media->id, ['path' => $media->path], []);
    }

    /**
     * Responsive varyantlar: GD ile ölçekli kopyalar (yalnız orijinalden küçük genişlikler).
     *
     * @return array<int, array{w: int, h: int, path: string}>
     */
    private function makeVariants(string $source, string $mime, int $width, int $height, string $base, string $extension): array
    {
        if (! function_exists('imagecreatefromstring')) {
            return [];
        }

        $image = @imagecreatefromstring((string) file_get_contents($source));

        if ($image === false) {
            return [];
        }

        $variants = [];

        foreach (self::VARIANT_WIDTHS as $w) {
            if ($w >= $width) {
                continue;
            }

            $h = (int) round($height * $w / $width);
            $resized = imagecreatetruecolor($w, $h);

            if ($mime !== 'image/jpeg') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
            }

            imagecopyresampled($resized, $image, 0, 0, 0, 0, $w, $h, $width, $height);
            ob_start();
            match ($mime) {
                'image/jpeg' => imagejpeg($resized, null, 82),
                'image/png' => imagepng($resized, null, 6),
                default => imagewebp($resized, null, 82),
            };
            $bytes = (string) ob_get_clean();
            imagedestroy($resized);
            $path = "{$base}-{$w}.{$extension}";

            if ($bytes !== '' && Storage::disk('public')->put($path, $bytes)) {
                $variants[] = ['w' => $w, 'h' => $h, 'path' => $path];
            }
        }

        imagedestroy($image);

        return $variants;
    }

    private function reject(User $uploader, string $stage, UploadedFile $file, string $detail, ?string $message = null): never
    {
        $this->audit->record($uploader, 'media.rejected', 'media', null, [], ['stage' => $stage, 'name' => mb_substr($file->getClientOriginalName(), 0, 120), 'detail' => mb_substr($detail, 0, 120)]);

        throw new DomainException($message ?? match ($stage) {
            'mime' => 'Yalnız JPEG, PNG ve WebP görsel kabul edilir.',
            'magic_bytes' => 'Dosya geçerli bir görsel değil (sihirli bayt doğrulaması).',
            'size' => 'Görsel 5 MB\'tan büyük olamaz.',
            default => 'Yükleme reddedildi.',
        });
    }

    private function clean(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
