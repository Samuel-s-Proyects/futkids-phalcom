<?php
declare(strict_types=1);
namespace App\Support;

final class SystemClock implements Clock
{
    private \DateTimeZone $timezone;

    public function __construct(string $timezone) {
        $this->timezone = new \DateTimeZone($timezone);
    }

    public function now(): \DateTimeImmutable {
        return new \DateTimeImmutable('now', $this->timezone);
    }

    public function today(): string {
        return $this->now()->format('Y-m-d');
    }
}
