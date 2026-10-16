<?php
declare(strict_types=1);
namespace App\Support;

/** Single source of "now" for business rules, so dates can be frozen in tests. */
interface Clock
{
    public function now(): \DateTimeImmutable;

    /** Current date as Y-m-d in the application time zone. */
    public function today(): string;
}
