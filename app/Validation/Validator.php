<?php
declare(strict_types=1);
namespace App\Validation;

use App\Exceptions\HttpException;
use App\Support\TextNormalizer;

/**
 * Reads and validates form input, collecting every field error before failing once.
 *
 *   $v = new Validator($input);
 *   $name = $v->text('name', max: 150, rule: 'title');
 *   $start = $v->date('start_date');
 *   $v->assertValid();   // throws HttpException(422) with all field messages
 *
 * Getters return null for missing or invalid values; only use them after assertValid().
 */
final class Validator
{
    public const REQUIRED = 'Este campo es obligatorio.';

    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(private array $input) {}

    public function has(string $field): bool {
        return array_key_exists($field, $this->input) && $this->raw($field) !== '';
    }

    public function text(string $field, int $max, ?string $rule = null, bool $required = true, bool $multiline = false): ?string {
        $value = $this->scalar($field);
        if ($value === null) return null;
        $value = $multiline ? TextNormalizer::cleanMultiline($value) : TextNormalizer::clean($value);
        if ($value === '') return $this->missing($field, $required);
        if (mb_strlen($value) > $max) return $this->fail($field, "Máximo {$max} caracteres.");
        if ($rule !== null && !Rules::matches($rule, $value)) return $this->fail($field, Rules::message($rule));
        return $value;
    }

    /** Only plain digit strings or integers are accepted: "+5", " 5", "5.0" and "1e3" are rejected. */
    public function integer(string $field, int $min, int $max, bool $required = true): ?int {
        $value = $this->scalar($field);
        if ($value === null) return null;
        if ($value === '') return $this->missing($field, $required);
        if (!preg_match('/^[0-9]+$/', $value) ||strlen(ltrim($value, '0')) > 18) return $this->fail($field, Rules::message('digits'));
        $number = (int)$value;
        if ($number < $min || $number > $max) return $this->fail($field, "Ingrese un número entre {$min} y {$max}.");
        return $number;
    }

    /** Strict Y-m-d calendar date. */
    public function date(string $field, bool $required = true): ?string {
        $value = $this->scalar($field);
        if ($value === null) return null;
        if ($value === '') return $this->missing($field, $required);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) return $this->fail($field, 'Ingrese una fecha válida.');
        return $value;
    }

    /** Strict Y-m-d\TH:i local date and time, returned as Y-m-d H:i:s. */
    public function dateTime(string $field, bool $required = true): ?string {
        $value = $this->scalar($field);
        if ($value === null) return null;
        if ($value === '') return $this->missing($field, $required);
        $moment = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
        if (!$moment || $moment->format('Y-m-d\TH:i') !== $value) return $this->fail($field, 'Seleccione una fecha y hora válidas.');
        return $moment->format('Y-m-d H:i:s');
    }

    /** @param list<string> $allowed */
    public function choice(string $field, array $allowed, string $message, bool $required = true): ?string {
        $value = $this->scalar($field);
        if ($value === null) return null;
        if ($value === '') return $this->missing($field, $required, $message);
        if (!in_array($value, $allowed, true)) return $this->fail($field, $message);
        return $value;
    }

    public function flag(string $field, string $message = 'Seleccione sí o no.'): ?int {
        $value = $this->choice($field, ['0', '1'], $message);
        return $value === null ? null : (int)$value;
    }

    public function email(string $field): ?string {
        $value = $this->text($field, 200);
        if ($value === null) return null;
        $value = mb_strtolower($value);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) return $this->fail($field, 'Ingrese un correo válido, por ejemplo nombre@dominio.com.');
        return $value;
    }

    /** Records a business-rule error for a field (keeps the first message per field). */
    public function fail(string $field, string $message): null {
        $this->errors[$field] ??= $message;
        return null;
    }

    public function hasError(string $field): bool {
        return isset($this->errors[$field]);
    }

    /** @return array<string, string> */
    public function errors(): array {
        return $this->errors;
    }

    public function assertValid(string $message = 'Revise los campos marcados del formulario.'): void {
        if ($this->errors) throw new HttpException($message, 422, $this->errors);
    }

    private function raw(string $field): mixed {
        $value = $this->input[$field] ?? null;
        return is_string($value) ? trim($value) : $value;
    }

    /** Returns the value as string ('' when absent) or null after recording an error for non-scalars. */
    private function scalar(string $field): ?string {
        $value = $this->raw($field);
        if ($value === null) return '';
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_int($value)) return (string)$value;
        if (!is_string($value)) return $this->fail($field, 'Valor inválido.');
        return $value;
    }

    private function missing(string $field, bool $required, string $message = self::REQUIRED): null {
        if ($required) $this->fail($field, $message);
        return null;
    }
}
