<?php

// Sıra ÖNEMLİ: site.php sonunda /{slug} yakalayıcısı var; panel route'ları
// ondan önce tanımlanmalı ki /panel bir "sayfa slug'ı" sanılmasın.
require __DIR__.'/panel.php';  // panel — auth -> staff.2fa -> tenant -> permission
require __DIR__.'/site.php';   // vitrin — kimlik doğrulaması yok
