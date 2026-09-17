<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $document->number }} — {{ $document->kindLabel() }}</title>
    <style>
        body { margin: 0; background: #e9e9e9; }
        .sheet { background: #fff; margin: 16px auto; max-width: 820px; box-shadow: 0 2px 12px rgba(0,0,0,.15); }
        .bar { max-width: 820px; margin: 12px auto 0; display: flex; gap: 8px; justify-content: flex-end; font-family: Arial, sans-serif; font-size: 13px; }
        .bar button, .bar a { padding: 8px 14px; border: 1px solid #999; background: #fff; border-radius: 6px; cursor: pointer; text-decoration: none; color: #222; }
        @media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; max-width: none; box-shadow: none; } }
    </style>
</head>
<body>
    <div class="bar"><a href="{{ route('panel.collections.documents.show', $document) }}">← Belgeye dön</a><a href="{{ route('panel.collections.documents.pdf', $document) }}">PDF indir</a><button type="button" onclick="window.print()">Yazdır</button></div>
    <div class="sheet">{!! $html !!}</div>
    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 150); });</script>
</body>
</html>
