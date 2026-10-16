<?php
declare(strict_types=1);
namespace App\Validation;

/**
 * Text patterns shared with the browser (config/validation-rules.json is also served to the front end),
 * so both sides accept and reject exactly the same input.
 */
final class Rules
{
    /** @var array<string, array{pattern: string, message: string}> */
    private static ?array $rules = null;

    public static function matches(string $rule, string $value): bool {
        return preg_match('/' . self::get($rule)['pattern'] . '/u', $value) === 1;
    }

    public static function message(string $rule): string {
        return self::get($rule)['message'];
    }

    /** @return array<string, array{pattern: string, message: string}> every rule, for the browser */
    public static function all(): array {
        return self::$rules ??= json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/config/validation-rules.json'), true, 8, JSON_THROW_ON_ERROR);
    }

    /** @return array{pattern: string, message: string} */
    private static function get(string $rule): array {
        return self::all()[$rule] ?? throw new \InvalidArgumentException("Regla de validación desconocida: {$rule}");
    }
}
