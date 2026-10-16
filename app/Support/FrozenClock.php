<?php
declare(strict_types=1);
namespace App\Support;

/** Fixed instant for automated tests (APP_FIXED_NOW). */
final class FrozenClock implements Clock
{
    public function __construct(private \DateTimeImmutable $instant) {}

    public static function at(string $dateTime, string $timezone): self {
        return new self(new \DateTimeImmutable($dateTime, new \DateTimeZone($timezone)));
    }

    public function now(): \DateTimeImmutable {
        return $this->instant;
    }

    public function today(): string {
        return $this->instant->format('Y-m-d');
    }

    public function advance(string $modifier): void {
        $this->instant = $this->instant->modify($modifier);
    }
}
