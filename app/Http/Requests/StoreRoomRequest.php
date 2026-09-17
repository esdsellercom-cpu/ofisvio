<?php

namespace App\Http\Requests;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Oda künyesi (geo.edit). Saat tutarlılığı (açılış < kapanış, slot katı) serviste. */
class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // yetki route middleware'inde (permission:geo.edit)
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'kind' => ['required', Rule::in(array_keys(Room::KINDS))],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'hourly_rate' => ['required', 'integer', 'min:0', 'max:100000'],
            'open_from' => ['required', 'date_format:H:i'],
            'open_until' => ['required', 'date_format:H:i'],
            'slot_minutes' => ['required', 'integer', Rule::in([30, 60, 120])],
            'max_hours' => ['required', 'integer', 'min:1', 'max:24'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'description' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array{name: string, kind: string, capacity: int, hourly_rate: int, open_from: string, open_until: string, slot_minutes: int, max_hours: int, is_active: bool, sort_order: int, description: string|null}
     */
    public function roomData(): array
    {
        $v = $this->validated();

        return [
            'name' => trim((string) $v['name']),
            'kind' => (string) $v['kind'],
            'capacity' => (int) $v['capacity'],
            'hourly_rate' => (int) $v['hourly_rate'],
            'open_from' => (string) $v['open_from'],
            'open_until' => (string) $v['open_until'],
            'slot_minutes' => (int) $v['slot_minutes'],
            'max_hours' => (int) $v['max_hours'],
            'is_active' => (bool) ($v['is_active'] ?? false),
            'sort_order' => (int) ($v['sort_order'] ?? 0),
            'description' => trim((string) ($v['description'] ?? '')) ?: null,
        ];
    }
}
