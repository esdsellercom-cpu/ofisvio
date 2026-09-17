{{-- Gelişmiş SEO ayarı alanı (faz 44): tip registry'den; $key, $def, $value, $disabled --}}
@php($field = \App\Services\SeoSettingsService::field($key))
@php($wide = in_array($def['type'], ['text', 'json', 'lines', 'rows', 'multi'], true))
<div class="field" style="{{ $wide ? 'grid-column:1/-1' : '' }}">
    @if ($def['type'] === 'bool')
        <label class="checkbox-row">
            <input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $value)) @disabled($disabled)>
            <span><strong>{{ $def['label'] }}</strong>@if ($def['description'] !== '') — {{ $def['description'] }}@endif</span>
        </label>
    @elseif ($def['type'] === 'multi')
        <span class="label">{{ $def['label'] }}</span>
        <div style="display:flex;flex-wrap:wrap;gap:8px 18px">
            @foreach ($def['options'] ?? [] as $optionValue => $optionLabel)
                <label class="checkbox-row"><input type="checkbox" name="{{ $field }}[]" value="{{ $optionValue }}" @checked(in_array($optionValue, (array) old($field, $value), true)) @disabled($disabled)><span>{{ $optionLabel }}</span></label>
            @endforeach
        </div>
        @if ($def['description'] !== '')<span class="small muted">{{ $def['description'] }}</span>@endif
    @elseif ($def['type'] === 'select')
        <span class="label">{{ $def['label'] }}</span>
        <select class="control" name="{{ $field }}" @disabled($disabled)>
            @foreach ($def['options'] ?? [] as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected((string) old($field, $value) === (string) $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        </select>
        @if ($def['description'] !== '')<span class="small muted">{{ $def['description'] }}</span>@endif
    @elseif ($def['type'] === 'rows')
        <span class="label">{{ $def['label'] }}</span>
        @php($rows = array_values(array_filter((array) old($field, $value), 'is_array')))
        @php($blank = $disabled ? 0 : 3)
        <div class="table-wrap">
            <table class="data">
                <thead><tr>@foreach ($def['columns'] as $column => $colDef)<th>{{ $colDef['label'] }}</th>@endforeach</tr></thead>
                <tbody>
                    @for ($i = 0; $i < count($rows) + $blank; $i++)
                        <tr>
                            @foreach ($def['columns'] as $column => $colDef)
                                @php($cell = (string) ($rows[$i][$column] ?? ''))
                                <td style="vertical-align:top">
                                    @if ($colDef['type'] === 'select')
                                        <select class="control" name="{{ $field }}[{{ $i }}][{{ $column }}]" @disabled($disabled)>
                                            @foreach ($colDef['options'] ?? [] as $optionValue => $optionLabel)
                                                <option value="{{ $optionValue }}" @selected($cell === (string) $optionValue)>{{ $optionLabel }}</option>
                                            @endforeach
                                        </select>
                                    @elseif (in_array($colDef['type'], ['text', 'json'], true))
                                        <textarea class="control {{ $colDef['type'] === 'json' ? 'mono' : '' }}" name="{{ $field }}[{{ $i }}][{{ $column }}]" style="min-height:52px" @disabled($disabled)>{{ $cell }}</textarea>
                                    @else
                                        <input class="control" type="text" name="{{ $field }}[{{ $i }}][{{ $column }}]" value="{{ $cell }}" @disabled($disabled)>
                                    @endif
                                    @if ($errors->has("{$field}.{$i}.{$column}"))<span class="field-error">{{ $errors->first("{$field}.{$i}.{$column}") }}</span>@endif
                                </td>
                            @endforeach
                        </tr>
                    @endfor
                </tbody>
            </table>
        </div>
        <span class="small muted">{{ $def['description'] }}@if (! $disabled) Boş satırlar yok sayılır; silmek için hücreleri boşaltın.@endif</span>
    @elseif (in_array($def['type'], ['text', 'json', 'lines'], true))
        <span class="label">{{ $def['label'] }}</span>
        <textarea class="control {{ $def['type'] === 'json' || str_starts_with($key, 'dev.') || str_starts_with($key, 'crawl.robots') || $key === 'crawl.sitemap_custom' ? 'mono' : '' }}" name="{{ $field }}" style="min-height:{{ $def['type'] === 'lines' ? 88 : 120 }}px" placeholder="{{ $def['placeholder'] ?? '' }}" @disabled($disabled)>{{ old($field, $value) }}</textarea>
        @if ($def['description'] !== '')<span class="small muted">{{ $def['description'] }}</span>@endif
    @else
        <span class="label">{{ $def['label'] }}</span>
        <input class="control {{ in_array($def['type'], ['int', 'url'], true) ? 'mono' : '' }}" type="{{ match ($def['type']) {'int' => 'number', 'url' => 'url', 'date' => 'date', default => 'text'} }}" name="{{ $field }}" value="{{ old($field, $value) }}" placeholder="{{ $def['placeholder'] ?? '' }}" @disabled($disabled)>
        @if ($def['description'] !== '')<span class="small muted">{{ $def['description'] }}</span>@endif
    @endif
    @if ($errors->has($field))<span class="field-error">{{ $errors->first($field) }}</span>@endif
</div>
