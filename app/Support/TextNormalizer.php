<?php
declare(strict_types=1);
namespace App\Support;

/** Canonical forms for user-entered text. */
final class TextNormalizer
{
    /** Single-line text: trimmed, NFC, inner whitespace collapsed to one space. */
    public static function clean(string $value): string {
        return trim((string)preg_replace('/\s+/u', ' ', self::nfc($value)));
    }

    /** Multi-line text: like clean() per line, keeping at most one blank line between paragraphs. */
    public static function cleanMultiline(string $value): string {
        $lines = array_map(
            fn(string $line) => trim((string)preg_replace('/[^\S\n]+/u', ' ', $line)),
            explode("\n", str_replace(["\r\n", "\r"], "\n", self::nfc($value))),
        );
        return trim((string)preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /**
     * Comparison key: case, accents and punctuation are ignored, so
     * "Cancha  2.", "cancha 2" and "CANCHÁ-2" are the same place.
     */
    public static function key(string $value): string {
        $decomposed = \Normalizer::normalize(self::clean($value), \Normalizer::FORM_D) ?: $value;
        $plain = (string)preg_replace('/\p{Mn}+/u', '', $decomposed);
        $plain = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($plain));
        return trim($plain);
    }

    private static function nfc(string $value): string {
        return \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
    }
}
