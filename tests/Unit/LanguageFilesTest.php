<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LanguageFilesTest extends TestCase
{
    private const DIR = __DIR__ . '/../../DomainResellerApi/resources/lang';

    private static function flatten(array $array, string $prefix = ''): array
    {
        $flat = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $flat += self::flatten($value, $prefix . $key . '.');
            } else {
                $flat[$prefix . $key] = $value;
            }
        }

        return $flat;
    }

    public static function locales(): array
    {
        $locales = [];
        foreach (glob(self::DIR . '/*', GLOB_ONLYDIR) as $dir) {
            if (basename($dir) !== 'en') {
                $locales[basename($dir)] = [basename($dir)];
            }
        }

        return $locales;
    }

    #[DataProvider('locales')]
    public function test_locale_matches_english(string $locale): void
    {
        $en = self::flatten(require self::DIR . '/en/messages.php');
        $other = self::flatten(require self::DIR . '/' . $locale . '/messages.php');

        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($other))), 'missing keys');
        $this->assertSame([], array_values(array_diff(array_keys($other), array_keys($en))), 'extra keys');

        foreach ($en as $key => $value) {
            if (str_contains($key, 'content_placeholder_')) {
                continue; // DNS examples such as "include:example.com" are not placeholders
            }
            preg_match_all('/:[a-z_]+/', $value, $expected);
            preg_match_all('/:[a-z_]+/', $other[$key], $actual);
            sort($expected[0]);
            sort($actual[0]);
            $this->assertSame($expected[0], $actual[0], "placeholders of $key");
        }
    }

    public function test_all_used_keys_exist(): void
    {
        $en = self::flatten(require self::DIR . '/en/messages.php');
        $source = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../DomainResellerApi')) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $source .= file_get_contents($file->getPathname());
            }
        }

        preg_match_all("/(?:trans|->t|__)\\(\\s*(?:\\\$m \\.\\s*)?'(?:domain-reseller-api::messages\\.)?([a-z_]+\\.[a-z_]+)'/", $source, $matches);
        $used = array_filter(array_unique($matches[1]), fn ($key) => !str_ends_with($key, "_"));
        $missing = array_values(array_diff($used, array_keys($en)));

        $this->assertNotEmpty($matches[1]);
        $this->assertSame([], $missing);
    }
}
