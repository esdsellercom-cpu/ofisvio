<?php

namespace App\Enums;

enum ContentKind: string
{
    case PAGE = 'page';   // /{slug} — Hakkımızda, Aydınlatma Metni ...
    case POST = 'post';   // /blog/{slug}

    public function label(): string
    {
        return match ($this) {
            self::PAGE => 'Sayfa',
            self::POST => 'Yazı',
        };
    }
}
