<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Exceptions\HttpException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testCollectsEveryFieldErrorBeforeFailing(): void {
        $v = new Validator(['name' => 'Copa <b>', 'goals' => '+5', 'start' => '2026-02-30']);
        $v->text('name', 150, 'title');
        $v->integer('goals', 0, 20);
        $v->date('start');
        $v->text('missing', 10);
        try {
            $v->assertValid();
            $this->fail('Se esperaba un error de validación.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame(['name', 'goals', 'start', 'missing'], array_keys($e->fields));
        }
    }

    public function testTextIsNormalisedBeforeChecking(): void {
        $v = new Validator(['name' => "  Leones   de\tPetapa "]);
        $this->assertSame('Leones de Petapa', $v->text('name', 100, 'title'));
        $v->assertValid();
    }

    public function testOptionalFieldsMayBeEmpty(): void {
        $v = new Validator(['shirt' => '', 'note' => '   ']);
        $this->assertNull($v->integer('shirt', 1, 99, required: false));
        $this->assertNull($v->text('note', 50, required: false));
        $this->assertSame([], $v->errors());
    }

    public function testIntegerAcceptsOnlyPlainDigitsInRange(): void {
        $v = new Validator(['a' => '7', 'b' => 9, 'c' => ' 5', 'd' => '1e3', 'e' => '100', 'f' => [1]]);
        $this->assertSame(7, $v->integer('a', 0, 99));
        $this->assertSame(9, $v->integer('b', 0, 99));
        $v->integer('c', 0, 99);
        $v->integer('d', 0, 99);
        $v->integer('e', 0, 99);
        $v->integer('f', 0, 99);
        $this->assertSame(['d', 'e', 'f'], array_keys($v->errors()), 'Los espacios exteriores se recortan; el resto se rechaza.');
    }

    public function testDateTimeIsStrict(): void {
        $v = new Validator(['ok' => '2026-10-20T14:30', 'bad' => '2026-10-20 14:30', 'impossible' => '2026-02-30T10:00']);
        $this->assertSame('2026-10-20 14:30:00', $v->dateTime('ok'));
        $v->dateTime('bad');
        $v->dateTime('impossible');
        $this->assertSame(['bad', 'impossible'], array_keys($v->errors()));
    }

    public function testChoiceFlagAndEmail(): void {
        $v = new Validator(['type' => 'league', 'flag' => '1', 'email' => ' Ana@FutKids.Local ', 'bad_email' => 'ana@']);
        $this->assertSame('league', $v->choice('type', ['knockout', 'league'], 'Tipo inválido.'));
        $this->assertSame(1, $v->flag('flag'));
        $this->assertSame('ana@futkids.local', $v->email('email'));
        $v->email('bad_email');
        $this->assertSame(['bad_email'], array_keys($v->errors()));
    }

    public function testBusinessRuleFailureKeepsFirstMessage(): void {
        $v = new Validator([]);
        $v->fail('end_date', 'Primero');
        $v->fail('end_date', 'Segundo');
        $this->assertSame(['end_date' => 'Primero'], $v->errors());
    }
}
