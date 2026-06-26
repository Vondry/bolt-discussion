<?php

declare(strict_types=1);

namespace BoltDiscussion\Tests\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

class TranslationCatalogueTest extends TestCase
{
    private const PLURAL_CATEGORIES = [
        'en' => ['one', 'other'],
        'cs' => ['one', 'few', 'other'],
        'de' => ['one', 'other'],
        'nl' => ['one', 'other'],
        'pl' => ['one', 'few', 'many', 'other'],
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function localeProvider(): iterable
    {
        foreach (array_keys(self::PLURAL_CATEGORIES) as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('localeProvider')]
    public function testCataloguesHaveMessageParityAndValidPlaceholders(string $locale): void
    {
        $english = $this->flatten($this->catalogue('en'));
        $translated = $this->flatten($this->catalogue($locale));

        $isPluralKey = static fn (string $key): bool => str_starts_with($key, 'reply_count.')
            || str_starts_with($key, 'comment_count.');
        $englishMessages = array_filter(
            $english,
            static fn (string $key): bool => ! $isPluralKey($key),
            ARRAY_FILTER_USE_KEY
        );
        $translatedMessages = array_filter(
            $translated,
            static fn (string $key): bool => ! $isPluralKey($key),
            ARRAY_FILTER_USE_KEY
        );

        $englishKeys = array_keys($englishMessages);
        $translatedKeys = array_keys($translatedMessages);
        sort($englishKeys);
        sort($translatedKeys);
        self::assertSame(
            $englishKeys,
            $translatedKeys,
            sprintf('Message keys differ for locale "%s".', $locale)
        );

        foreach ($translatedMessages as $key => $message) {
            self::assertNotSame('', trim($message), sprintf('Translation "%s" is empty for locale "%s".', $key, $locale));
            self::assertSame(
                $this->placeholders($englishMessages[$key]),
                $this->placeholders($message),
                sprintf('Placeholders differ for "%s" in locale "%s".', $key, $locale)
            );
        }

        foreach (['reply_count', 'comment_count'] as $pluralPrefix) {
            $pluralKeys = array_map(
                static fn (string $category): string => $pluralPrefix . '.' . $category,
                self::PLURAL_CATEGORIES[$locale]
            );
            $actualPluralKeys = array_values(array_filter(
                array_keys($translated),
                static fn (string $key): bool => str_starts_with($key, $pluralPrefix . '.')
            ));

            self::assertSame(
                $pluralKeys,
                $actualPluralKeys,
                sprintf('Plural categories differ for "%s" in locale "%s".', $pluralPrefix, $locale)
            );
            foreach ($pluralKeys as $key) {
                self::assertStringContainsString(
                    '%count%',
                    $translated[$key],
                    sprintf('Plural translation "%s" must contain %%count%%.', $key)
                );
            }
        }
    }

    #[DataProvider('localeProvider')]
    public function testSymfonyLoadsEveryCatalogueInTheExpectedDomain(string $locale): void
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', $this->cataloguePath($locale), $locale, 'bolt_discussion');
        $catalogue = $this->flatten($this->catalogue($locale));

        self::assertSame(
            $catalogue['Post comment'],
            $translator->trans('Post comment', [], 'bolt_discussion'),
            sprintf('The "%s" catalogue was not loaded in the bolt_discussion domain.', $locale)
        );
        self::assertStringContainsString(
            '2',
            str_replace(
                '%count%',
                '2',
                $translator->trans('reply_count.other', [], 'bolt_discussion')
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogue(string $locale): array
    {
        $catalogue = Yaml::parseFile($this->cataloguePath($locale));
        self::assertIsArray($catalogue);

        return $catalogue;
    }

    private function cataloguePath(string $locale): string
    {
        return \dirname(__DIR__, 2) . '/translations/bolt_discussion.' . $locale . '.yaml';
    }

    /**
     * @param array<string, mixed> $messages
     * @return array<string, string>
     */
    private function flatten(array $messages, string $prefix = ''): array
    {
        $flat = [];
        foreach ($messages as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $flat += $this->flatten($value, $fullKey);
            } else {
                $flat[$fullKey] = (string) $value;
            }
        }

        return $flat;
    }

    /**
     * @return string[]
     */
    private function placeholders(string $message): array
    {
        preg_match_all('/%[A-Za-z0-9_]+%/', $message, $matches);
        $placeholders = array_values(array_unique($matches[0]));
        sort($placeholders);

        return $placeholders;
    }
}
