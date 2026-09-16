{{-- İç bağlantı önerileri (faz 18/23): aynı sitede yayındaki içerik; puan = ortak etiket ×3, aynı kategori ×2, başlık kelimesi ×1. --}}
@if (! empty($suggestions))
    <div class="panel">
        <p class="eyebrow">İç bağlantı önerileri</p>
        <ul class="stack" style="gap:10px;margin:0;padding:0;list-style:none">
            @foreach ($suggestions as $s)
                <li>
                    <strong>{{ $s['content']->title }}</strong> <span class="small muted">· {{ implode(' · ', $s['reasons']) }}</span>
                    <code class="small" style="display:block;margin-top:3px;user-select:all">[{{ $s['content']->title }}]({{ $s['content']->path() }})</code>
                </li>
            @endforeach
        </ul>
    </div>
@endif