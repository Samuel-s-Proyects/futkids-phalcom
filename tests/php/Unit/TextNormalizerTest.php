<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Support\TextNormalizer;
use PHPUnit\Framework\TestCase;

final class TextNormalizerTest extends TestCase
{
    public function testCleanTrimsAndCollapsesWhitespace(): void {
        $this->assertSame('Cancha 2 Las Palmas', TextNormalizer::clean("  Cancha \t 2\n Las   Palmas "));
    }

    public function testCleanComposesAccents(): void {
        $decomposed = "Jose\u{0301}";
        $this->assertSame('José', TextNormalizer::clean($decomposed));
    }

    public function testMultilineKeepsParagraphs(): void {
        $this->assertSame("Primera línea\n\nSegunda", TextNormalizer::cleanMultiline("  Primera \t línea \r\n\r\n\r\n\r\nSegunda  "));
    }

    public function testKeyIgnoresCaseAccentsAndPunctuation(): void {
        $expected = TextNormalizer::key('Cancha 2');
        $this->assertSame('cancha 2', $expected);
        foreach (['cancha  2', 'CANCHA 2.', 'Cánchá-2', ' Cancha, 2 '] as $variant) $this->assertSame($expected, TextNormalizer::key($variant), $variant);
        $this->assertNotSame($expected, TextNormalizer::key('Cancha 3'));
    }
}
