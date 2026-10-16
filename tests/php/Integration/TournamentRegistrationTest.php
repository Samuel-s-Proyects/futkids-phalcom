<?php
declare(strict_types=1);
namespace Tests\Integration;

use Tests\Support\IntegrationTestCase;

final class TournamentRegistrationTest extends IntegrationTestCase
{
    public function testTieBreakRulesAreValidatedAndPersisted(): void {
        $tournament = $this->createTournament();
        $this->assertSame('knockout', $tournament['type']);
        $this->assertSame('draft', $tournament['status']);
        $this->assertSame([2, 10, 5, 1, 60], array_map('intval', [
            $tournament['extra_time_periods'], $tournament['extra_time_period_minutes'],
            $tournament['penalty_attempts_per_team'], $tournament['penalty_sudden_death'], $tournament['match_duration_minutes'],
        ]));
        $this->assertRejected(fn() => $this->createTournament(['extra_time_periods' => 5]), 422, 'extra_time_periods');
        $this->assertRejected(fn() => $this->createTournament(['penalty_attempts_per_team' => '+3']), 422, 'penalty_attempts_per_team');
    }

    public function testExtraTimeIsOptional(): void {
        $tournament = $this->createTournament(['extra_time_periods' => 0, 'extra_time_period_minutes' => '']);
        $this->assertSame(0, (int)$tournament['extra_time_periods']);
        $this->assertNull($tournament['extra_time_period_minutes']);
    }

    public function testDatesMustBeOrderedAndNotInThePast(): void {
        $this->assertRejected(fn() => $this->createTournament(['start_date' => '2026-11-02', 'end_date' => '2026-11-01']), 422, 'end_date');
        $this->assertRejected(fn() => $this->createTournament(['start_date' => '2026-10-15', 'end_date' => '2026-11-01']), 422, 'start_date');
        $this->assertSame('2026-10-16', $this->createTournament(['start_date' => '2026-10-16'])['start_date'], 'Hoy es una fecha de inicio válida.');
    }

    public function testNameRulesAndUniqueness(): void {
        $this->createTournament(['name' => 'Copa Apertura 2026']);
        $this->assertRejected(fn() => $this->createTournament(['name' => 'copa  apertura 2026']), 422, 'name');
        $this->assertRejected(fn() => $this->createTournament(['name' => 'Copa <2026>']), 422, 'name');
        $this->assertRejected(fn() => $this->createTournament(['name' => '']), 422, 'name');
    }

    public function testEveryInvalidFieldIsReportedAtOnce(): void {
        $error = $this->assertRejected(fn() => $this->tournaments->save(['name' => '', 'start_date' => 'mañana', 'end_date' => '', 'extra_time_periods' => 'x', 'penalty_attempts_per_team' => '', 'penalty_sudden_death' => '2']));
        $this->assertEqualsCanonicalizing(['name', 'start_date', 'end_date', 'extra_time_periods', 'penalty_attempts_per_team', 'penalty_sudden_death'], array_keys($error->fields));
    }

    public function testManualOrderAndClosing(): void {
        $id = (int)$this->createTournament()['id'];
        $this->tournaments->openRegistration($id);
        $this->tournaments->enroll($id, 1);
        $this->tournaments->enroll($id, 2);
        $this->assertRejected(fn() => $this->tournaments->enroll($id, 1), 409, 'team_id');

        $this->tournaments->orderParticipants($id, [2, 1]);
        $this->assertSame(2, (int)$this->tournaments->detail($id)['participants'][0]['team_id']);
        $this->assertRejected(fn() => $this->tournaments->orderParticipants($id, [2, 2]), 422, 'team_ids');

        $closed = $this->tournaments->closeRegistration($id, 'manual');
        $this->assertSame('manual', $closed['draw_method']);
        $this->assertSame('2026-10-16 09:00:00', $closed['registrations_closed_at']);
        $this->assertRejected(fn() => $this->tournaments->removeParticipant($id, 2), 409);
    }

    public function testRemovingATeamRenumbersSeeds(): void {
        $id = (int)$this->createTournament()['id'];
        $this->tournaments->openRegistration($id);
        foreach ([1, 2, 3] as $team) $this->tournaments->enroll($id, $team);
        $this->tournaments->removeParticipant($id, 1);
        $participants = $this->tournaments->detail($id)['participants'];
        $this->assertSame([[2, 1], [3, 2]], array_map(fn($p) => [(int)$p['team_id'], (int)$p['seed_order']], $participants));
    }

    public function testInactiveTeamsCannotEnroll(): void {
        $team = $this->catalog->save('teams', ['name' => 'Equipo inactivo', 'active' => 0]);
        $id = (int)$this->createTournament()['id'];
        $this->tournaments->openRegistration($id);
        $this->assertRejected(fn() => $this->tournaments->enroll($id, (int)$team['id']), 409, 'team_id');
    }

    public function testRandomDrawAndInvalidMethod(): void {
        $id = (int)$this->createTournament()['id'];
        $this->tournaments->openRegistration($id);
        $this->tournaments->enroll($id, 1);
        $this->tournaments->enroll($id, 2);
        $this->assertRejected(fn() => $this->tournaments->closeRegistration($id, 'invalid'), 422, 'draw_method');
        $this->assertSame('random', $this->tournaments->closeRegistration($id, 'random')['draw_method']);
    }

    public function testClosingNeedsTwoTeams(): void {
        $id = (int)$this->createTournament()['id'];
        $this->tournaments->openRegistration($id);
        $this->tournaments->enroll($id, 1);
        $this->assertRejected(fn() => $this->tournaments->closeRegistration($id, 'random'), 409);
    }

    public function testOnlyDraftTournamentsCanBeEdited(): void {
        $tournament = $this->createTournament();
        $edited = $this->tournaments->save(['name' => 'Nombre corregido'] + $tournament, (int)$tournament['id']);
        $this->assertSame('Nombre corregido', $edited['name']);
        $this->tournaments->openRegistration((int)$tournament['id']);
        $this->assertRejected(fn() => $this->tournaments->save(['name' => 'Otro nombre'] + $tournament, (int)$tournament['id']), 409);
    }

    public function testLifecycleGuidesTheOrganiser(): void {
        $id = (int)$this->createTournament()['id'];
        $states = fn() => array_column($this->tournaments->detail($id)['lifecycle']['steps'], 'state', 'key');
        $this->assertSame('open-registration', $this->tournaments->detail($id)['lifecycle']['next_action']['key']);
        $this->assertSame(['setup' => 'current', 'registration' => 'pending', 'schedule' => 'pending', 'matches' => 'pending', 'result' => 'pending'], $states());

        $this->tournaments->openRegistration($id);
        $lifecycle = $this->tournaments->detail($id)['lifecycle'];
        $this->assertSame('close-registration', $lifecycle['next_action']['key']);
        $this->assertNotEmpty($lifecycle['blockers'], 'Sin equipos no se puede cerrar la inscripción.');

        foreach ([1, 2] as $team) $this->tournaments->enroll($id, $team);
        $this->tournaments->closeRegistration($id, 'manual');
        $this->assertSame('generate-bracket', $this->tournaments->detail($id)['lifecycle']['next_action']['key']);

        $this->tournaments->generateBracket($id);
        $this->assertNull($this->tournaments->detail($id)['lifecycle']['next_action']);
        $this->assertSame('current', $states()['matches']);
    }
}
