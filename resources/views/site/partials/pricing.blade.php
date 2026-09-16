@if (! empty($blocks['plans']))
<section id="uyelik" class="wrap section">
    <div class="section-head">
        <div style="min-width:0">
            <p class="eyebrow">06 — Üyelikler</p>
            <h2 class="h2">{{ $texts['pricing_title'] }}</h2>
        </div>
        <p class="body-muted" style="margin:0;max-width:32ch;font-size:16px">{{ $blocks['pricing_note'] }}</p>
    </div>

    <div class="table-wrap">
        <table class="compare">
            <caption class="hp">Üyelik tiplerinin özellik karşılaştırması</caption>
            <thead>
                <tr>
                    <th scope="col" class="label" style="font-weight:500">Özellik</th>
                    @foreach ($blocks['plans'] as $plan)
                        <th scope="col">
                            <div style="font-size:17px;font-weight:600;letter-spacing:-.015em">{{ $plan['name'] }}</div>
                            <div class="mono" style="margin-top:6px;font-size:13px;color:var(--brand);font-weight:400">{{ $plan['price'] }}</div>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($blocks['plan_rows'] as $row)
                    <tr>
                        <th scope="row" style="text-align:left;padding:16px 22px;font-size:15px;font-weight:500;border-bottom:1px solid var(--line-soft)">{{ $row['label'] }}</th>
                        @foreach ($row['cells'] as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
@endif
