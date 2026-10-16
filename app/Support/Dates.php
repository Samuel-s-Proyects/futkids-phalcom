<?php
declare(strict_types=1);
namespace App\Support;

/** Date formatting for messages shown to people (dd/mm/yyyy). */
final class Dates
{
    public static function display(string $date): string {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
        return $parsed ? $parsed->format('d/m/Y') : $date;
    }

    public static function displayDateTime(string $dateTime): string {
        return (new \DateTimeImmutable($dateTime))->format('d/m/Y H:i');
    }
}
