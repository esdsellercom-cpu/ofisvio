<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    /**
     * Her test temiz önbellekle başlar.
     *
     * Yerelde CACHE_STORE=array olduğundan önbellek zaten test başına
     * sıfırlanır; CI ise (quality-gate.yml) üretim gibi Redis kullanır ve
     * hız sınırı sayaçları, 2FA replay anahtarları testler arasında BİRİKİR
     * — RefreshDatabase kullanıcı id'lerini 1'den başlattığı için anahtarlar
     * da çakışır. İlk pipeline koşusunda 7 test bu yüzden 429 aldı. Test
     * izolasyonu veritabanı gibi önbellek için de sağlanır.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }
}
