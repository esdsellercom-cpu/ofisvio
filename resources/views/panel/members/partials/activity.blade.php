{{-- Aktivite zaman çizelgesi (faz 51): denetim izinden — kim · ne yaptı · ne zaman --}}
@if ($rows->isEmpty())
    <div class="empty-state" style="border:0">Kayıt yok.</div>
@else
    <div class="rows">
        @foreach ($rows as $log)
            <div class="row">
                <div class="main-t"><b>{{ $activityLabel($log) }}</b><span class="small">{{ $log->actor?->name ?? 'Sistem' }} · {{ $log->created_at?->format('d.m.Y H:i') }} · <span class="mono muted">{{ $log->entity_type }}#{{ $log->entity_id }}</span></span></div>
                <span class="rt small muted">{{ $log->created_at?->diffForHumans() }}</span>
            </div>
        @endforeach
    </div>
@endif
