<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Validation\Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The same cases run in the browser suite (tests/js/unit/validation.test.mjs). */
final class ValidationRulesTest extends TestCase
{
    public static function cases(): iterable {
        $fixture = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/validation-cases.json'), true, 8, JSON_THROW_ON_ERROR);
        foreach ($fixture as $rule => $groups) {
            foreach ($groups['valid'] as $value) yield "{$rule} acepta " . json_encode($value, JSON_UNESCAPED_UNICODE) => [$rule, $value, true];
            foreach ($groups['invalid'] as $value) yield "{$rule} rechaza " . json_encode($value, JSON_UNESCAPED_UNICODE) => [$rule, $value, false];
        }
    }

    #[DataProvider('cases')]
    public function testRule(string $rule, string $value, bool $expected): void {
        $this->assertSame($expected, Rules::matches($rule, $value));
    }

    public function testEveryRuleHasAMessage(): void {
        foreach (['person_name', 'title', 'description', 'digits'] as $rule) $this->assertNotSame('', Rules::message($rule));
    }
}
