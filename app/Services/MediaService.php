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
 * Medya kütüphanesi (faz 30): vitrin görselleri (kapak, hero). Zincir KYC ile aynı
 * ilkededir — MIME (sunucu tarafı, uzantıya değil içeriğe bakar) → uzantı → sihirli
 * bayt (getimagesize) → boyut → ClamAV (tarayıcı yoksa yükleme REDDEDİLİR,
 * enfekte dosya asla yazılmaz) → sha256 → public diske UUID adla.
 *
 * Görseller kamuya açık içindir; bu yüzden 'public' disk. Aynı sha256 aynı sitede
 * ikinci kez yüklenirse mevcut kayıt döner (yinelenen dosya yok).
 */
class MediaService
{
    public const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private readonly MalwareScanner $scanner) {}

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

    /**
     * Seçim listeleri için (kapak/hero).
     *
     * @return Collection<int, Media>
     */
    public function all(Website $website): Collection
    {
        return Media::query()->where('website_id', $website->id)->orderByDesc('created_at')->get();
    }

    public function upload(User $uploader, Website $website, UploadedFile $file, ?string $alt): Media
    {
        $mime = (string) $file->getMimeType(); // içerikten (finfo), istemci başlığından değil

        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            throw new DomainException('Yalnız JPEG, PNG ve WebP görsel kabul edilir.');
        }

        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'webp',
        };

        if ($file->getSize() > self::MAX_BYTES) {
            throw new DomainException('Görsel 5 MB\'tan büyük olamaz.');
        }

        $path = (string) $file->getRealPath();
        $dimensions = @getimagesize($path);

        if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1) {
            throw new DomainException('Dosya geçerli bir görsel değil (sihirli bayt doğrulaması).');
        }

        $scan = $this->scanner->scan($path);

        if (! $scan->available) {
            throw new DomainException('Güvenlik taraması yapılamadı; yükleme kabul edilmedi. Daha sonra yeniden deneyin.');
        }

        if ($scan->isInfected()) {
            throw new DomainException('Güvenlik taraması dosyada zararlı içerik buldu ('.$scan->signature.'). Dosya kaydedilmedi.');
        }

        $hash = hash_file('sha256', $path) ?: '';
        $existing = Media::query()->where('website_id', $website->id)->where('checksum_sha256', $hash)->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($uploader, $website, $file, $mime, $extension, $dimensions, $hash, $alt) {
            $stored = $file->storeAs('media/'.$website->id, Str::uuid()->toString().'.'.$extension, 'public');

            if ($stored === false) {
                throw new DomainException('Görsel diske yazılamadı.');
            }

            return Media::create([
                'website_id' => $website->id,
                'uploaded_by' => $uploader->id,
                'disk' => 'public',
                'path' => $stored,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 190),
                'mime_type' => $mime,
                'size_bytes' => (int) $file->getSize(),
                'width' => (int) $dimensions[0],
                'height' => (int) $dimensions[1],
                'checksum_sha256' => $hash,
                'alt' => $alt !== null && trim($alt) !== '' ? trim($alt) : null,
            ]);
        });
    }

    public function updateAlt(Media $media, ?string $alt): Media
    {
        $media->alt = $alt !== null && trim($alt) !== '' ? trim($alt) : null;
        $media->save();

        return $media;
    }

    /** Silme: kullanan içerik/site varsa reddedilir (kırık görsel bırakmaz). Dosya da silinir. */
    public function delete(Media $media): void
    {
        if ($media->contents()->exists() || $media->heroOf()->exists()) {
            throw new DomainException('Bu görsel kullanımda (kapak ya da hero); önce oradan kaldırın.');
        }

        Storage::disk($media->disk)->delete($media->path);
        $media->delete();
    }
}
