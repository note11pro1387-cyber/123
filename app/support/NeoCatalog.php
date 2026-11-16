<?php
declare(strict_types=1);

/**
 * Canonical, context-aware catalog shared by the NEO browser form and server DTOs.
 * The JSON contains public project-choice metadata only; secrets/configuration never belong here.
 */
final class NeoCatalog
{
    private const RELATIVE_PATH = 'assets/data/neo-catalog.json';
    private static ?array $catalog = null;

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }

        $root = defined('MRNEON_PROJECT_ROOT') ? MRNEON_PROJECT_ROOT : dirname(__DIR__, 2);
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::RELATIVE_PATH);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('The canonical NEO catalog is unavailable.');
        }

        $json = file_get_contents($path);
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('The canonical NEO catalog could not be read.');
        }

        try {
            $catalog = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The canonical NEO catalog is invalid JSON.', 0, $exception);
        }
        if (!is_array($catalog)) {
            throw new RuntimeException('The canonical NEO catalog has an invalid root structure.');
        }

        self::assertCatalogShape($catalog);
        self::$catalog = $catalog;
        return self::$catalog;
    }

    /** @return list<string> Allowed values from an explicitly named catalog collection. */
    public static function values(string $collection): array
    {
        $items = self::all()[$collection] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new InvalidArgumentException('Unknown NEO catalog collection.');
        }

        $values = [];
        foreach ($items as $item) {
            if (($item['allowed'] ?? false) === true && is_string($item['value'] ?? null)) {
                $values[] = $item['value'];
            }
        }
        return $values;
    }

    /** @return list<string> Allowed purposes for one industry; purpose values are context-specific. */
    public static function purposeValuesForIndustry(string $industry): array
    {
        $industryItem = self::findItem('industries', $industry);
        if ($industryItem === null) {
            return [];
        }

        $values = [];
        foreach ($industryItem['purposes'] as $purpose) {
            if (($purpose['allowed'] ?? false) === true && is_string($purpose['value'] ?? null)) {
                $values[] = $purpose['value'];
            }
        }
        return $values;
    }

    /** Return a display label; never expose an unknown machine value as user-facing copy. */
    public static function label(string $collection, string $value): string
    {
        $item = self::findItem($collection, $value);
        return is_string($item['label'] ?? null) && $item['label'] !== ''
            ? $item['label']
            : 'نامشخص';
    }

    public static function purposeLabel(string $industry, string $purpose): string
    {
        $industryItem = self::findItem('industries', $industry);
        if ($industryItem !== null) {
            foreach ($industryItem['purposes'] as $item) {
                if (($item['allowed'] ?? false) === true && ($item['value'] ?? null) === $purpose
                    && ($item['context'] ?? null) === $industry && is_string($item['label'] ?? null)
                    && $item['label'] !== '') {
                    return $item['label'];
                }
            }
        }
        return 'نامشخص';
    }

    public static function contentOptionLabel(string $field, string $value): string
    {
        foreach (self::all()['content'] as $question) {
            if (($question['allowed'] ?? false) !== true || ($question['id'] ?? null) !== $field) {
                continue;
            }
            foreach ($question['options'] as $option) {
                if (($option['allowed'] ?? false) === true && ($option['value'] ?? null) === $value
                    && ($option['context'] ?? null) === $field && is_string($option['label'] ?? null)
                    && $option['label'] !== '') {
                    return $option['label'];
                }
            }
        }
        return 'نامشخص';
    }

    /** @param array<string,mixed> $content @return array{logo:string,text:string,images:string} */
    public static function contentLabels(array $content): array
    {
        $labels = ['logo' => '', 'text' => '', 'images' => ''];
        foreach (array_keys($labels) as $field) {
            $value = $content[$field] ?? null;
            if (is_string($value) && $value !== '') {
                $labels[$field] = self::contentOptionLabel($field, $value);
            }
        }
        return $labels;
    }

    /** @return array<string,list<string>> */
    public static function contentValuesByField(): array
    {
        $result = [];
        foreach (self::all()['content'] as $question) {
            if (($question['allowed'] ?? false) !== true || !is_string($question['id'] ?? null)) {
                continue;
            }
            $values = [];
            foreach ($question['options'] as $option) {
                if (($option['allowed'] ?? false) === true && is_string($option['value'] ?? null)) {
                    $values[] = $option['value'];
                }
            }
            $result[$question['id']] = $values;
        }
        return $result;
    }

    /** @param array<string,mixed> $catalog */
    private static function assertCatalogShape(array $catalog): void
    {
        if (($catalog['schemaVersion'] ?? null) !== 1) {
            throw new RuntimeException('The canonical NEO catalog version is unsupported.');
        }

        $collections = ['industries', 'palettes', 'styles', 'features', 'content', 'timelines', 'messengers'];
        foreach ($collections as $collection) {
            if (!isset($catalog[$collection]) || !is_array($catalog[$collection])
                || !array_is_list($catalog[$collection]) || $catalog[$collection] === []) {
                throw new RuntimeException('The canonical NEO catalog is missing a required collection.');
            }
        }

        self::assertUniqueItems($catalog['industries'], 'value');
        self::assertUniqueItems($catalog['palettes'], 'value');
        self::assertUniqueItems($catalog['styles'], 'value');
        self::assertUniqueItems($catalog['features'], 'value');
        self::assertUniqueItems($catalog['timelines'], 'value');
        self::assertUniqueItems($catalog['messengers'], 'value', true);
        self::assertUniqueItems($catalog['content'], 'id');

        foreach ($catalog['industries'] as $industry) {
            self::assertMetadataItem($industry, 'value', 'industry', null);
            if (!is_array($industry['purposes'] ?? null) || !array_is_list($industry['purposes'])
                || $industry['purposes'] === []) {
                throw new RuntimeException('The canonical NEO industry has no valid purpose catalog.');
            }
            self::assertUniqueItems($industry['purposes'], 'value');
            foreach ($industry['purposes'] as $purpose) {
                self::assertMetadataItem($purpose, 'value', 'purpose', $industry['value']);
            }
        }

        $categories = [
            'palettes' => 'color-palette',
            'styles' => 'style',
            'features' => 'feature',
            'timelines' => 'timeline',
            'messengers' => 'messenger',
        ];
        foreach ($categories as $collection => $category) {
            foreach ($catalog[$collection] as $item) {
                self::assertMetadataItem($item, 'value', $category, null, $collection === 'messengers');
                if ($collection === 'palettes') {
                    if (!is_array($item['colors'] ?? null) || !array_is_list($item['colors'])
                        || count($item['colors']) < 3) {
                        throw new RuntimeException('The canonical NEO color palette is malformed.');
                    }
                    foreach ($item['colors'] as $color) {
                        if (!is_string($color) || !preg_match('/^#[a-fA-F0-9]{6}$/D', $color)) {
                            throw new RuntimeException('The canonical NEO color palette has an invalid color.');
                        }
                    }
                }
            }
        }

        $contentIds = [];
        foreach ($catalog['content'] as $question) {
            if (!is_array($question) || !is_string($question['id'] ?? null) || $question['id'] === '') {
                throw new RuntimeException('The canonical NEO content question is malformed.');
            }
            $contentIds[] = $question['id'];
            self::assertMetadataItem($question, 'id', 'content-status', $question['id']);
            if (!is_array($question['options'] ?? null) || !array_is_list($question['options'])
                || $question['options'] === []) {
                throw new RuntimeException('The canonical NEO content question has no valid options.');
            }
            self::assertUniqueItems($question['options'], 'value');
            foreach ($question['options'] as $option) {
                self::assertMetadataItem($option, 'value', 'content-option', $question['id']);
            }
        }
        sort($contentIds, SORT_STRING);
        if ($contentIds !== ['images', 'logo', 'text']) {
            throw new RuntimeException('The canonical NEO content fields do not match the stable brief contract.');
        }
    }

    /** Validate the full metadata contract shared with the browser, not only the identifier. */
    private static function assertMetadataItem(
        mixed $item,
        string $identifierKey,
        string $category,
        ?string $context,
        bool $allowEmptyIdentifier = false,
    ): void {
        if (!is_array($item)
            || !is_string($item[$identifierKey] ?? null)
            || (!$allowEmptyIdentifier && $item[$identifierKey] === '')
            || !is_string($item['label'] ?? null) || $item['label'] === ''
            || ($item['category'] ?? null) !== $category
            || !array_key_exists('context', $item) || ($item['context'] ?? null) !== $context
            || !is_bool($item['allowed'] ?? null)
            || !is_int($item['sortOrder'] ?? null) || $item['sortOrder'] < 0
            || (array_key_exists('emoji', $item) && !is_string($item['emoji']))) {
            throw new RuntimeException('The canonical NEO catalog contains a malformed item.');
        }
    }

    /** @param list<mixed> $items */
    private static function assertUniqueItems(array $items, string $key, bool $allowEmpty = false): void
    {
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item[$key] ?? null)
                || (!$allowEmpty && $item[$key] === '')) {
                throw new RuntimeException('The canonical NEO catalog contains an invalid identifier.');
            }
            if (in_array($item[$key], $seen, true)) {
                throw new RuntimeException('The canonical NEO catalog contains a duplicate identifier.');
            }
            $seen[] = $item[$key];
        }
    }

    /** @return array<string,mixed>|null */
    private static function findItem(string $collection, string $value): ?array
    {
        $items = self::all()[$collection] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new InvalidArgumentException('Unknown NEO catalog collection.');
        }
        foreach ($items as $item) {
            if (is_array($item) && ($item['value'] ?? null) === $value && ($item['allowed'] ?? false) === true) {
                return $item;
            }
        }
        return null;
    }
}
