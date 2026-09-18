<?php

namespace App\Seo;

/**
 * JSON-LD doğrulayıcı (faz 60, Schema Manager): dış servis yok. Yapısal kurallar (@context, @type, @graph),
 * schema.org tür başına zorunlu/önerilen alanlar ve tarih/URL biçimleri denetlenir. Bulgular okunur metindir;
 * "hata" üretimi engellemez, Command Center'da Schema kategorisine düşer.
 */
class SchemaValidator
{
    /** Tür → [zorunlu alanlar, önerilen alanlar]. */
    public const RULES = [
        'Organization' => [['name', 'url'], ['logo', 'sameAs', 'telephone', 'email', 'address']],
        'LocalBusiness' => [['name', 'address'], ['telephone', 'openingHoursSpecification', 'geo', 'image', 'url']],
        'WebSite' => [['name', 'url'], ['publisher']],
        'WebPage' => [['name', 'url'], ['description', 'datePublished', 'dateModified']],
        'Article' => [['headline', 'datePublished', 'author', 'publisher'], ['image', 'dateModified', 'description']],
        'BlogPosting' => [['headline', 'datePublished', 'author', 'publisher'], ['image', 'dateModified', 'description']],
        'BreadcrumbList' => [['itemListElement'], []],
        'FAQPage' => [['mainEntity'], []],
        'Service' => [['name', 'provider'], ['description', 'areaServed', 'url', 'offers']],
        'Event' => [['name', 'startDate', 'location'], ['endDate', 'description', 'image', 'offers', 'organizer']],
        'Person' => [['name'], ['url', 'jobTitle']],
        'PostalAddress' => [['addressLocality', 'addressCountry'], ['streetAddress', 'postalCode']],
        'Product' => [['name'], ['description', 'offers', 'image']],
    ];

    /** Bu türlerin alt türleri (CoworkingSpace, Corporation vb.) aile kuralıyla denetlenir. */
    private const FAMILIES = [
        'Corporation' => 'Organization', 'ProfessionalService' => 'LocalBusiness', 'CoworkingSpace' => 'LocalBusiness', 'Store' => 'LocalBusiness',
        'NewsArticle' => 'Article', 'TechArticle' => 'Article', 'CollectionPage' => 'WebPage', 'AboutPage' => 'WebPage', 'ContactPage' => 'WebPage',
        'BusinessEvent' => 'Event', 'EducationEvent' => 'Event', 'SocialEvent' => 'Event',
    ];

    /**
     * @param  array<string, mixed>  $jsonLd
     * @return array{errors: list<string>, warnings: list<string>, types: list<string>}
     */
    public function validate(array $jsonLd): array
    {
        $errors = [];
        $warnings = [];
        $types = [];

        if ($jsonLd === []) {
            return ['errors' => [], 'warnings' => ['Sayfada JSON-LD yok.'], 'types' => []];
        }

        if (($jsonLd['@context'] ?? null) !== 'https://schema.org') {
            $errors[] = '@context "https://schema.org" olmalı.';
        }

        $nodes = isset($jsonLd['@graph']) ? (is_array($jsonLd['@graph']) ? $jsonLd['@graph'] : []) : [array_diff_key($jsonLd, ['@context' => 1])];

        if ($nodes === []) {
            $errors[] = '@graph boş.';
        }

        $ids = [];

        foreach ($nodes as $i => $node) {
            if (! is_array($node)) {
                $errors[] = 'Düğüm #'.($i + 1).' nesne değil.';

                continue;
            }

            $type = $node['@type'] ?? null;

            if (! is_string($type) || $type === '') {
                $errors[] = 'Düğüm #'.($i + 1).': @type eksik.';

                continue;
            }

            $types[] = $type;

            if (isset($node['@id'])) {
                if (isset($ids[$node['@id']])) {
                    $warnings[] = $type.': @id "'.$node['@id'].'" birden fazla düğümde (aynı kimlik farklı düğümlere verilmiş).';
                }
                $ids[$node['@id']] = true;
            }

            $this->checkNode($type, $node, $errors, $warnings);
        }

        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings)), 'types' => array_values(array_unique($types))];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function checkNode(string $type, array $node, array &$errors, array &$warnings): void
    {
        $family = self::FAMILIES[$type] ?? $type;
        [$required, $recommended] = self::RULES[$family] ?? [[], []];

        foreach ($required as $field) {
            if (! $this->present($node, $field)) {
                $errors[] = $type.': zorunlu alan "'.$field.'" eksik.';
            }
        }

        foreach ($recommended as $field) {
            if (! $this->present($node, $field)) {
                $warnings[] = $type.': önerilen alan "'.$field.'" yok.';
            }
        }

        foreach (['datePublished', 'dateModified', 'startDate', 'endDate', 'foundingDate'] as $dateField) {
            if (isset($node[$dateField]) && (! is_string($node[$dateField]) || preg_match('/^\d{4}-\d{2}-\d{2}/', $node[$dateField]) !== 1)) {
                $errors[] = $type.': "'.$dateField.'" ISO 8601 tarih olmalı (YYYY-MM-DD…).';
            }
        }

        foreach (['url', 'image', 'logo'] as $urlField) {
            $value = $node[$urlField] ?? null;
            $value = is_array($value) ? ($value['url'] ?? $value[0] ?? null) : $value;

            if (is_string($value) && $value !== '' && preg_match('#^https?://#', $value) !== 1) {
                $errors[] = $type.': "'.$urlField.'" mutlak adres olmalı ('.mb_substr($value, 0, 60).').';
            }
        }

        if ($family === 'BreadcrumbList') {
            $items = is_array($node['itemListElement'] ?? null) ? $node['itemListElement'] : [];

            if (count($items) < 2) {
                $warnings[] = 'BreadcrumbList: en az iki öğe olmalı.';
            }

            foreach ($items as $k => $item) {
                if (! is_array($item) || ($item['@type'] ?? '') !== 'ListItem' || ! isset($item['position'], $item['name'])) {
                    $errors[] = 'BreadcrumbList: öğe #'.($k + 1).' ListItem/position/name taşımalı.';
                } elseif ((int) $item['position'] !== $k + 1) {
                    $errors[] = 'BreadcrumbList: position sırası bozuk (#'.($k + 1).').';
                }
            }
        }

        if ($family === 'FAQPage') {
            $questions = is_array($node['mainEntity'] ?? null) ? $node['mainEntity'] : [];

            if (count($questions) < 2) {
                $warnings[] = 'FAQPage: en az iki soru olmalı.';
            }

            foreach ($questions as $k => $q) {
                if (! is_array($q) || ($q['@type'] ?? '') !== 'Question' || trim((string) ($q['name'] ?? '')) === '' || trim((string) ($q['acceptedAnswer']['text'] ?? '')) === '') {
                    $errors[] = 'FAQPage: soru #'.($k + 1).' Question/name/acceptedAnswer.text taşımalı.';
                }
            }
        }

        if ($family === 'Event' && isset($node['offers']) && is_array($node['offers']) && ! isset($node['offers']['price'])) {
            $errors[] = 'Event: offers price taşımalı.';
        }

        // Uydurma sinyali: derecelendirme/yorum düğümleri gerçek veri kaynağı olmadan üretilmez (politika).
        foreach (['aggregateRating', 'review'] as $forbidden) {
            if (isset($node[$forbidden])) {
                $warnings[] = $type.': "'.$forbidden.'" alanı yalnız gerçek toplanan yorum verisiyle kullanılmalı.';
            }
        }

        // İç içe adres/organizasyon düğümleri
        foreach (['address', 'location', 'publisher', 'organizer', 'author'] as $nested) {
            if (isset($node[$nested]) && is_array($node[$nested]) && isset($node[$nested]['@type']) && is_string($node[$nested]['@type']) && ! isset($node[$nested]['@id'])) {
                $this->checkNode($node[$nested]['@type'], $node[$nested], $errors, $warnings);
            }
        }
    }

    /** @param  array<string, mixed>  $node */
    private function present(array $node, string $field): bool
    {
        if (! array_key_exists($field, $node)) {
            return false;
        }

        $value = $node[$field];

        return ! ($value === null || $value === '' || $value === []);
    }
}
