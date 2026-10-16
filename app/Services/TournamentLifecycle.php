<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\Tournament;

/**
 * Where a tournament stands and what the organiser should do next, for the step indicator in the UI.
 * Each step is "done", "current" or "pending".
 */
final class TournamentLifecycle
{
    /**
     * @return array{steps: list<array{key: string, label: string, state: string}>, next_action: ?array{key: string, label: string}, hint: string, blockers: list<string>}
     */
    public static function describe(Tournament $tournament, int $participants): array {
        $league = $tournament->isLeague();
        $closed = $tournament->registrations_closed_at !== null;
        $scheduled = $league ? $tournament->fixture_generated_at !== null : $tournament->bracket_generated_at !== null;
        $finished = $tournament->status === 'finished';
        $reached = [
            'setup' => true,
            'registration' => $tournament->status !== 'draft',
            'schedule' => $closed,
            'matches' => $scheduled,
            'result' => $finished,
        ];
        $labels = [
            'setup' => 'Configuración',
            'registration' => 'Inscripción de equipos',
            'schedule' => $league ? 'Calendario de jornadas' : 'Cuadro de eliminación',
            'matches' => 'Partidos',
            'result' => $league ? 'Tabla final' : 'Campeón',
        ];
        $current = count(array_filter($reached)) - 1;
        $steps = [];
        foreach (array_keys($labels) as $index => $key) {
            $state = $finished || $index < $current ? 'done' : ($index === $current ? 'current' : 'pending');
            $steps[] = ['key' => $key, 'label' => $labels[$key], 'state' => $state];
        }

        $blockers = [];
        [$action, $hint] = match (true) {
            $finished => [null, 'El torneo terminó. Puede consultar resultados, goleadores y reportes.'],
            $scheduled => [null, 'Programe cada partido, regístrelo durante el juego y confirme el resultado.'],
            $closed => [['key' => $league ? 'generate-fixture' : 'generate-bracket', 'label' => $league ? 'Generar calendario' : 'Generar cuadro'], 'Las inscripciones están cerradas. Genere los partidos para empezar.'],
            $tournament->status === 'registration' => [['key' => 'close-registration', 'label' => 'Cerrar inscripciones'], 'Inscriba los equipos participantes y luego cierre la inscripción.'],
            default => [['key' => 'open-registration', 'label' => 'Abrir inscripciones'], 'Revise la configuración y abra las inscripciones cuando esté listo.'],
        };
        $minimum = $league ? 3 : 2;
        if ($action && $action['key'] === 'close-registration' && $participants < $minimum) {
            $blockers[] = "Inscriba al menos {$minimum} equipos (hay {$participants}).";
        }
        return ['steps' => $steps, 'next_action' => $action, 'hint' => $hint, 'blockers' => $blockers];
    }
}
