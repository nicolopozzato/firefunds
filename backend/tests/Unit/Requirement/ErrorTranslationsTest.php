<?php

namespace App\Tests\Unit\Requirement;

use App\Core\Exception\ErrorCode;
use App\Core\Validation\ViolationCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Requirement: the app shows every API error in the user's language. The
 * frontend translates the codes the API sends (ErrorCode, ViolationCode), so
 * each one needs its text in every language of frontend/src/i18n/locales
 * (the whole repository is mounted on /app in the dev containers).
 */
class ErrorTranslationsTest extends TestCase
{
    private const LOCALES_DIR = __DIR__.'/../../../../frontend/src/i18n/locales';
    private const LANGUAGES = ['it', 'en'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function languages(): iterable
    {
        foreach (self::LANGUAGES as $language) {
            yield $language => [$language];
        }
    }

    #[DataProvider('languages')]
    public function testEveryErrorCodeHasAText(string $language): void
    {
        $texts = $this->errors($language)['api'] ?? [];

        foreach (ErrorCode::cases() as $case) {
            $code = $case->value;
            $this->assertTrue(
                isset($texts[$code]) || isset($texts[$code.'_other']),
                \sprintf('errors.api.%s is missing in %s.json', $code, $language),
            );
        }
    }

    #[DataProvider('languages')]
    public function testEveryViolationCodeHasAText(string $language): void
    {
        $texts = $this->errors($language)['validation'] ?? [];

        foreach ((new \ReflectionClass(ViolationCode::class))->getConstants() as $code) {
            if (\is_string($code)) {
                $this->assertArrayHasKey($code, $texts, \sprintf('errors.validation.%s is missing in %s.json', $code, $language));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function errors(string $language): array
    {
        $file = self::LOCALES_DIR.'/'.$language.'.json';
        $this->assertFileExists($file, 'Frontend locales not found: the whole repository must be mounted on /app (see .lando.yml).');

        return json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR)['errors'];
    }
}
