<?php

/*
 * Rol etiketleri — RBAC matrisindeki 12 rol (rbac_scope_permission_matrix.csv).
 * roles.label kolonu seed'de İngilizce türetilir; ekranda bu dosya kullanılır.
 */
return [
    // Müşteri (company) rolleri
    'owner' => 'Sahip',
    'legal_representative' => 'Yasal temsilci',
    'company_admin' => 'Şirket yöneticisi',
    'accountant' => 'Muhasebeci',
    'employee' => 'Çalışan',
    'viewer' => 'İzleyici',

    // Ofisvio personeli (internal) rolleri
    'super_admin' => 'Süper yönetici',
    'system_admin' => 'Sistem yöneticisi',
    'finance_admin' => 'Finans yöneticisi',
    'operations_admin' => 'Operasyon yöneticisi',
    'reception' => 'Resepsiyon',
    'location_manager' => 'Lokasyon yöneticisi',
];
