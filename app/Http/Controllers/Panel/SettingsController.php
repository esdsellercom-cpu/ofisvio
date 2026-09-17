<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\GeoService;
use App\Services\SettingsService;
use App\Settings\SettingsRegistry;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ayar merkezi (master prompt §31–33). settings.view görür, settings.manage yazar.
 * Kurulum kapsamı varsayılan; ?lokasyon=<id> ile lokasyon üzerine yazma (kalıtım).
 * Doğrulama kuralları SettingsRegistry'den — formda olmayan anahtar yazılamaz.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly GeoService $geo,
    ) {}

    public function index(Request $request): View
    {
        [$scope, $scopeId, $location] = $this->scopeFrom($request);

        return view('panel.settings.index', [
            'groups' => SettingsRegistry::GROUPS,
            'data' => $this->settings->formData($scope, $scopeId),
            'scope' => $scope,
            'location' => $location,
            'locations' => $this->geo->allLocations(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        [$scope, $scopeId] = $this->scopeFrom($request);
        $rules = [];

        foreach (SettingsRegistry::definitions() as $key => $def) {
            if (in_array($scope, $def['scopes'], true)) {
                $rules[str_replace('.', '__', $key)] = array_merge(['nullable'], $def['rules']);
            }
        }

        $validated = $request->validate($rules);

        try {
            foreach ($validated as $field => $value) {
                $key = str_replace('__', '.', $field);
                $def = SettingsRegistry::definition($key);
                // Checkbox: gönderilmeyen bool = false (kalıtıma dönüş için "miras" seçeneği ayrı).
                if ($def['type'] === 'bool' && $request->input($field.'_inherit')) {
                    $value = null;
                } elseif ($def['type'] === 'bool') {
                    $value = $request->boolean($field);
                }
                $this->settings->set($request->user(), $key, $value, $scope, $scopeId);
            }
        } catch (DomainException $e) {
            return back()->withErrors(['settings' => $e->getMessage()])->withInput();
        }

        return redirect()->route('panel.settings.index', $scopeId ? ['lokasyon' => $scopeId] : [])->with('status', 'Ayarlar kaydedildi.');
    }

    /** @return array{0: string, 1: int|null, 2: Location|null} */
    private function scopeFrom(Request $request): array
    {
        $locationId = (int) $request->query('lokasyon', '0');

        if ($locationId <= 0) {
            return ['installation', null, null];
        }

        $location = $this->geo->allLocations()->firstWhere('id', $locationId);

        if ($location === null) {
            abort(404);
        }

        return ['location', $location->id, $location];
    }
}
