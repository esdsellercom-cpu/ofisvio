<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Location;
use App\Models\Media;
use App\Models\Service;
use App\Services\ContentService;
use App\Services\LiveEditService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Canlı düzenleme uçları (faz 59): vitrindeki modal tek form gönderimiyle buraya gelir (JS'ten HTTP yok). Yetki rotada
 * hedef türüne göre (website.manage / content.edit / service.manage / geo.edit); oturum bayrağı (aç/kapat) da burada.
 * Dönüş adresi yalnız site içi yol; hata mesajı forma flash ile döner.
 */
class LiveEditController extends Controller
{
    public function __construct(private readonly LiveEditService $live, private readonly ContentService $contents) {}

    public function toggle(Request $request): RedirectResponse
    {
        $on = $request->boolean('on');
        $request->session()->put('live_edit', $on);

        return redirect()->to(self::returnPath($request))->with('live_status', $on ? 'Düzenleme modu açık: görsele tıklayın.' : 'Düzenleme modu kapatıldı.');
    }

    public function website(Request $request): RedirectResponse
    {
        return $this->handle($request, function (array $data, $media) use ($request) {
            $this->live->setWebsiteHero($request->user(), $this->contents->defaultWebsite(), $media);
        });
    }

    public function section(Request $request): RedirectResponse
    {
        return $this->handle($request, function (array $data, $media) use ($request) {
            $this->live->setSectionMedia($request->user(), $this->contents->defaultWebsite(), (int) $data['id'], (string) $data['field'], isset($data['index']) && $data['index'] !== '' ? (int) $data['index'] : null, $media);
        });
    }

    public function content(Request $request, Content $content): RedirectResponse
    {
        return $this->handle($request, function (array $data, $media) use ($request, $content) {
            $this->live->setContentCover($request->user(), $content->website, $content, $media);
        });
    }

    public function service(Request $request, Service $service): RedirectResponse
    {
        return $this->handle($request, function (array $data, $media) use ($request, $service) {
            $this->live->setServiceCover($request->user(), $this->contents->defaultWebsite(), $service, $media);
        });
    }

    public function location(Request $request, Location $location): RedirectResponse
    {
        return $this->handle($request, function (array $data, $media) use ($request, $location) {
            $this->live->setLocationCover($request->user(), $this->contents->defaultWebsite(), $location, $media);
        });
    }

    /** @param  callable(array<string, mixed>, Media|null): void  $apply */
    private function handle(Request $request, callable $apply): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:replace,remove'],
            'id' => ['nullable', 'integer'],
            'field' => ['nullable', 'string', 'max:40', 'regex:/^[a-z_]+$/'],
            'index' => ['nullable', 'integer', 'min:0'],
            'media_id' => ['nullable', 'integer'],
            'file' => ['nullable', 'file', 'image', 'max:10240'],
            'alt' => ['nullable', 'string', 'max:190'],
            'caption' => ['nullable', 'string', 'max:300'],
            'return' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $website = $this->contents->defaultWebsite();
            $media = $data['action'] === 'remove'
                ? null
                : $this->live->resolveMedia($request->user(), $website, isset($data['media_id']) ? (int) $data['media_id'] : null, $request->file('file'), ['alt' => $data['alt'] ?? null, 'caption' => $data['caption'] ?? null]);

            if ($data['action'] === 'replace' && $media === null) {
                throw new DomainException('Bir görsel seçin ya da yükleyin.');
            }

            $apply($data, $media);
        } catch (DomainException $e) {
            return redirect()->to(self::returnPath($request))->with('live_error', $e->getMessage());
        }

        return redirect()->to(self::returnPath($request))->with('live_status', $data['action'] === 'remove' ? 'Görsel kaldırıldı.' : 'Görsel güncellendi.');
    }

    /** Dönüş: yalnız site içi yol (açık yönlendirme yok). */
    private static function returnPath(Request $request): string
    {
        $path = (string) $request->input('return', '/');

        return preg_match('~^/(?!/)[^\s]*$~', $path) === 1 && ! str_starts_with($path, '/panel') ? $path : '/';
    }
}
