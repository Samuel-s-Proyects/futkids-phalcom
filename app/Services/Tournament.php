<?php
declare(strict_types=1);
namespace App\Services;
use App\Exceptions\HttpException;
use App\Models\{Team, Player, Matchday, Goal, Incident, User, Tournament as TournamentRecord, TournamentTeam, TournamentMatch, TournamentMatchPeriodScore, TournamentMatchGoal, TournamentMatchGoalEvent, TournamentMatchPenalty, TournamentMatchResultAudit};
use Phalcon\Filter\Validation;
use Phalcon\Filter\Validation\Validator\{PresenceOf, StringLength};

/** Business rules live here, independently of HTTP and the interface. */
class Tournament
{
    private const MODELS = ['teams' => Team::class, 'players' => Player::class, 'matchdays' => Matchday::class, 'goals' => Goal::class, 'incidents' => Incident::class];
    private const FIELDS = [
        'teams' => ['name', 'active'],
        'players' => ['first_name', 'last_name', 'birth_date', 'team_id', 'shirt_number', 'position'],
        'matchdays' => ['number', 'game_date'],
        'goals' => ['player_id', 'matchday_id', 'goals'],
        'incidents' => ['player_id', 'description', 'card_type', 'incident_date', 'suspension_date'],
    ];
    private $db;
    private array $settings;
    public function __construct(private \Phalcon\Di\DiInterface $di) {
        $this->db = $di->getShared('db');
        $this->settings = $di->getShared('settings');
    }
    private function model(string $entity): string {
        if (!isset(self::MODELS[$entity])) throw new HttpException('Catálogo no encontrado.', 404);
        return self::MODELS[$entity];
    }
    private function record(string $entity, int $id) {
        $class = $this->model($entity);
        $model = $class::findFirst($id);
        if (!$model) throw new HttpException('El registro no existe.', 404);
        return $model;
    }
    private function startWrite(): void {
        $this->db->begin();
        // Serializes mutations so a red card and a goal cannot race each other.
        if ($this->settings['database']['driver'] === 'mysql') $this->db->fetchOne('SELECT id FROM tournament_lock WHERE id = 1 FOR UPDATE');
    }
    public function state(): array {
        $result = [];
        foreach (self::MODELS as $entity => $class) {
            $result[$entity] = $class::find(['order' => $entity === 'matchdays' ? 'number ASC' : 'id ASC'])->toArray();
        }
        $result['tournaments'] = $this->tournaments();
        $result['tournament_teams'] = TournamentTeam::find(['order' => 'tournament_id ASC, seed_order ASC'])->toArray();
        $result['tournament_matches'] = TournamentMatch::find(['order' => 'tournament_id ASC, round_number ASC, match_number ASC'])->toArray();
        $result['tournament_match_period_scores'] = TournamentMatchPeriodScore::find(['order' => 'tournament_match_id ASC, period_number ASC'])->toArray();
        $result['tournament_match_goals'] = TournamentMatchGoal::find(['order' => 'tournament_match_id ASC, player_id ASC'])->toArray();
        $result['tournament_match_goal_events'] = TournamentMatchGoalEvent::find(['order' => 'tournament_match_id ASC, minute ASC, id ASC'])->toArray();
        $result['tournament_match_penalties'] = TournamentMatchPenalty::find(['order' => 'tournament_match_id ASC, kick_number ASC'])->toArray();
        $result['referees'] = User::find(['conditions' => "role = 'referee'", 'columns' => 'id, name, email', 'order' => 'name ASC'])->toArray();
        return $result;
    }
    private function tournamentRecord(int $id): TournamentRecord {
        $tournament = TournamentRecord::findFirst($id);
        if (!$tournament) throw new HttpException('El torneo no existe.', 404);
        return $tournament;
    }
    public function tournaments(): array {
        return TournamentRecord::find(['order' => 'start_date DESC, id DESC'])->toArray();
    }
    public function tournament(int $id): array {
        $tournament = $this->tournamentRecord($id)->toArray();
        $tournament['participants'] = TournamentTeam::find([
            'conditions' => 'tournament_id = :tournament:',
            'bind' => ['tournament' => $id],
            'order' => 'seed_order ASC',
        ])->toArray();
        $tournament['matches'] = TournamentMatch::find([
            'conditions' => 'tournament_id = :tournament:',
            'bind' => ['tournament' => $id],
            'order' => 'round_number ASC, match_number ASC',
        ])->toArray();
        return $tournament;
    }
    public function saveTournament(array $input, int $id = 0): array {
        $data = array_intersect_key($input, array_flip(['name', 'start_date', 'end_date', 'extra_time_periods', 'extra_time_period_minutes', 'penalty_attempts_per_team', 'penalty_sudden_death']));
        foreach ($data as $field => $value) {
            if (!is_scalar($value)) throw new HttpException('Valor inválido en el formulario.', 422, [$field => 'Valor inválido.']);
            $data[$field] = trim((string)$value);
        }
        $this->required($data, ['name', 'start_date', 'end_date', 'extra_time_periods', 'extra_time_period_minutes', 'penalty_attempts_per_team'], ['name' => 150]);
        $data['extra_time_periods'] = $this->integer($data['extra_time_periods'], 'extra_time_periods', 1, 2147483647);
        $data['extra_time_period_minutes'] = $this->integer($data['extra_time_period_minutes'], 'extra_time_period_minutes', 1, 2147483647);
        $data['penalty_attempts_per_team'] = $this->integer($data['penalty_attempts_per_team'], 'penalty_attempts_per_team', 1, 2147483647);
        if (!array_key_exists('penalty_sudden_death', $data) || !in_array($data['penalty_sudden_death'], ['0', '1'], true)) {
            throw new HttpException('Seleccione si aplica muerte súbita.', 422, ['penalty_sudden_death' => 'Seleccione sí o no.']);
        }
        $data['penalty_sudden_death'] = (int)$data['penalty_sudden_death'];
        $start = $this->date($data['start_date'], 'start_date');
        $end = $this->date($data['end_date'], 'end_date');
        if ($start > $end) throw new HttpException('La fecha inicial no puede ser posterior a la fecha final.', 422, ['end_date' => 'Seleccione una fecha igual o posterior al inicio.']);
        $this->startWrite();
        try {
            $tournament = $id ? $this->tournamentRecord($id) : new TournamentRecord();
            if ($id && $tournament->status !== 'draft') throw new HttpException('Solo se pueden editar los datos de un torneo en borrador.', 409);
            $tournament->assign($data);
            if (!$tournament->save()) throw new HttpException('No se pudo guardar el torneo: ' . implode('; ', array_map('strval', $tournament->getMessages())));
            $this->db->commit();
            return $tournament->toArray();
        } catch (\Throwable $e) {
            if ($this->db->isUnderTransaction()) $this->db->rollback();
            throw $e;
        }
    }
    public function openRegistration(int $id): array {
        $this->startWrite();
        try {
            $tournament = $this->tournamentRecord($id);
            if ($tournament->status !== 'draft') throw new HttpException('Solo se puede abrir la inscripción de un torneo en borrador.', 409);
            $tournament->status = 'registration';
            if (!$tournament->save()) throw new HttpException('No se pudo abrir la inscripción.', 409);
            $this->db->commit();
            return $tournament->toArray();
        } catch (\Throwable $e) {
            if ($this->db->isUnderTransaction()) $this->db->rollback();
            throw $e;
        }
    }
    public function enrollTeam(int $tournamentId, int $teamId): array {
        $this->startWrite();
        try {
            $tournament = $this->tournamentRecord($tournamentId);
            if ($tournament->status !== 'registration' || $tournament->registrations_closed_at !== null) throw new HttpException('Las inscripciones no están abiertas.', 409);
            $team = $this->record('teams', $teamId);
            if (!(int)$team->active) throw new HttpException('No se pueden inscribir equipos inactivos.', 409);
            $existing = TournamentTeam::findFirst(['conditions' => 'tournament_id = :t: AND team_id = :team:', 'bind' => ['t' => $tournamentId, 'team' => $teamId]]);
            if ($existing) throw new HttpException('El equipo ya está inscrito en este torneo.', 409);
            $max = $this->db->fetchOne('SELECT COALESCE(MAX(seed_order), 0) AS last_order FROM tournament_teams WHERE tournament_id = ?', \Phalcon\Db\Enum::FETCH_ASSOC, [$tournamentId]);
            $participant = new TournamentTeam();
            $participant->assign(['tournament_id' => $tournamentId, 'team_id' => $teamId, 'seed_order' => (int)$max['last_order'] + 1]);
            if (!$participant->save()) throw new HttpException('No se pudo inscribir el equipo.', 409);
            $this->db->commit();
            return $participant->toArray();
        } catch (\Throwable $e) {
            if ($this->db->isUnderTransaction()) $this->db->rollback();
            throw $e;
        }
    }
    public function removeParticipant(int $tournamentId, int $teamId): void {
        $this->startWrite();
        try {
            $tournament = $this->tournamentRecord($tournamentId);
            if ($tournament->status !== 'registration' || $tournament->registrations_closed_at !== null) throw new HttpException('No se pueden cambiar los participantes después de cerrar la inscripción.', 409);
            $participant = TournamentTeam::findFirst(['conditions' => 'tournament_id = :t: AND team_id = :team:', 'bind' => ['t' => $tournamentId, 'team' => $teamId]]);
            if (!$participant) throw new HttpException('El equipo no está inscrito en este torneo.', 404);
            if (!$participant->delete()) throw new HttpException('No se pudo retirar el equipo.', 409);
            $this->db->execute('UPDATE tournament_teams SET seed_order = -seed_order WHERE tournament_id = ?', [$tournamentId]);
            $remaining = TournamentTeam::find(['conditions' => 'tournament_id = :t:', 'bind' => ['t' => $tournamentId], 'order' => 'seed_order DESC']);
            $order = $remaining->count();
            foreach ($remaining as $row) $this->db->execute('UPDATE tournament_teams SET seed_order = ? WHERE id = ?', [$order--, $row->id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isUnderTransaction()) $this->db->rollback();
            throw $e;
        }
    }
    public function orderParticipants(int $tournamentId, array $teamIds): array {
        $this->startWrite();
        try {
            $tournament = $this->tournamentRecord($tournamentId);
            if ($tournament->status !== 'registration' || $tournament->registrations_closed_at !== null) throw new HttpException('El orden no se puede cambiar después de cerrar la inscripción.', 409);
            if (!$teamIds) throw new HttpException('El orden debe incluir cada equipo inscrito una sola vez.', 422);
            foreach ($teamIds as $teamId) if (!is_scalar($teamId)) throw new HttpException('El orden contiene un equipo inválido.', 422, ['team_ids' => 'Use identificadores enteros.']);
            if (count(array_unique($teamIds)) !== count($teamIds)) throw new HttpException('El orden debe incluir cada equipo inscrito una sola vez.', 422);
            $normalized = array_map(fn($teamId) => $this->integer($teamId, 'team_ids', 1, PHP_INT_MAX), $teamIds);
            $actual = array_map('intval', array_column(TournamentTeam::find(['conditions' => 'tournament_id = :t:', 'bind' => ['t' => $tournamentId]])->toArray(), 'team_id'));
            $expected = $normalized;
            sort($actual); sort($expected);
            if ($actual !== $expected) throw new HttpException('El orden debe contener exactamente los equipos inscritos.', 422);
            $this->db->execute('UPDATE tournament_teams SET seed_order = -seed_order WHERE tournament_id = ?', [$tournamentId]);
            foreach ($normalized as $index => $teamId) $this->db->execute('UPDATE tournament_teams SET seed_order = ? WHERE tournament_id = ? AND team_id = ?', [$index + 1, $tournamentId, $teamId]);
            $this->db->commit();
            return $this->tournament($tournamentId);
        } catch (\Throwable $e) {
            if ($this->db->isUnderTransaction()) $this->db->rollback();
            throw $e;
        }
    }
    public function closeRegistration(int $id, string $drawMethod): array {
        if (!in_array($drawMethod, ['random', 'manual'], true)) throw new HttpException('Seleccione sorteo aleatorio u orden manual.', 422, ['draw_method' => 'Opción inválida.']);
        $this->startWrite();
        try {
            $tournament = $this->tournamentRecord($id);
            if ($tournament->status !== 'registration' || $tournament->registrations_closed_at !== null) throw new HttpException('Las inscripciones ya están cerradas o no están abiertas.', 409);
            $participants = TournamentTeam::find(['conditions' => 'tournament_id = :t:', 'bind' => ['t' => $id], 'order' => 'seed_order ASC']);
            if ($participants->count() < 2) throw new HttpException('Inscriba al menos dos equipos antes de cerrar las inscripciones.', 409);
            if ($drawMethod === 'manual') {
                $orders = array_map('intval', array_column($participants->toArray(), 'seed_order'));
                if ($orders !== range(1, count($orders))) throw new HttpException('Guarde primero el orden manual de todos los equipos.', 409);
            }
            $tournament->draw_method = $drawMethod;
            $tournament->registrations_closed_at = date('Y-m-d H:i:s');
            if (!$tournament->save()) throw new HttpException('No se pudieron cerrar las inscripciones.', 409);
            $this->db->commit();
            return $this->tournament($id);
        } catch (\Throwable $e) {
            if ($this->db->isUnderTransaction()) $this->db->rollback();
            throw $e;
        }
    }
    public function generateBracket(int $id): array {
        $this->startWrite();
        try {
            $tournament = $this->tournamentRecord($id);
            $existing = TournamentMatch::findFirst([
                'conditions' => 'tournament_id = :tournament:',
                'bind' => ['tournament' => $id],
            ]);
            if ($existing || $tournament->bracket_generated_at !== null) {
                $this->db->commit();
                return $this->tournament($id);
            }
            if ($tournament->registrations_closed_at === null) throw new HttpException('Cierre las inscripciones antes de generar el cuadro.', 409);
            $participants = TournamentTeam::find([
                'conditions' => 'tournament_id = :tournament:',
                'bind' => ['tournament' => $id],
                'order' => 'seed_order ASC',
            ])->toArray();
            $teamIds = array_map('intval', array_column($participants, 'team_id'));
            if (count($teamIds) < 2) throw new HttpException('Se requieren al menos dos equipos para generar el cuadro.', 409);
            if ($tournament->draw_method === 'random') {
                shuffle($teamIds);
                $this->db->execute('UPDATE tournament_teams SET seed_order = -seed_order WHERE tournament_id = ?', [$id]);
                foreach ($teamIds as $index => $teamId) {
                    $this->db->execute('UPDATE tournament_teams SET seed_order = ? WHERE tournament_id = ? AND team_id = ?', [$index + 1, $id, $teamId]);
                }
            }
            $size = 1;
            while ($size < count($teamIds)) $size *= 2;
            $seeds = [1, 2];
            for ($bracketSize = 4; $bracketSize <= $size; $bracketSize *= 2) {
                $nextSeeds = [];
                foreach ($seeds as $seed) {
                    $nextSeeds[] = $seed;
                    $nextSeeds[] = $bracketSize + 1 - $seed;
                }
                $seeds = $nextSeeds;
            }
            $slots = array_fill(0, $size, null);
            $slotBySeed = array_flip($seeds);
            foreach ($teamIds as $index => $teamId) $slots[$slotBySeed[$index + 1]] = $teamId;
            $roundMatches = [];
            $roundCount = (int)log($size, 2);
            for ($position = 0; $position < $size; $position += 2) {
                $home = $slots[$position];
                $away = $slots[$position + 1];
                if ($home === null && $away === null) continue;
                $match = new TournamentMatch();
                $match->assign([
                    'tournament_id' => $id,
                    'round_number' => 1,
                    'match_number' => intdiv($position, 2) + 1,
                    'home_team_id' => $home,
                    'away_team_id' => $away,
                    'winner_team_id' => $home === null || $away === null ? ($home ?? $away) : null,
                    'status' => $home === null || $away === null ? 'bye' : 'pending',
                    'workflow_status' => $home === null || $away === null ? 'bye' : 'unprogrammed',
                    'result_revision' => 0,
                ]);
                if (!$match->save()) throw new HttpException('No se pudo guardar un partido del cuadro: ' . implode('; ', array_map('strval', $match->getMessages())), 409);
                $roundMatches[1][(int)$match->match_number] = $match;
            }
            for ($round = 2; $round <= $roundCount; $round++) {
                $matchesInRound = intdiv($size, 2 ** $round);
                for ($number = 1; $number <= $matchesInRound; $number++) {
                    $left = $roundMatches[$round - 1][($number * 2) - 1] ?? null;
                    $right = $roundMatches[$round - 1][$number * 2] ?? null;
                    $match = new TournamentMatch();
                    $match->assign([
                        'tournament_id' => $id,
                        'round_number' => $round,
                        'match_number' => $number,
                        'home_team_id' => $left && $left->status === 'bye' ? $left->winner_team_id : null,
                        'away_team_id' => $right && $right->status === 'bye' ? $right->winner_team_id : null,
                        'status' => 'pending',
                        'workflow_status' => 'unprogrammed',
                        'result_revision' => 0,
                    ]);
                    if (!$match->save()) throw new HttpException('No se pudo guardar un partido del cuadro: ' . implode('; ', array_map('strval', $match->getMessages())), 409);
                    $roundMatches[$round][$number] = $match;
                }
            }
            $tournament->bracket_generated_at = date('Y-m-d H:i:s');
            if (!$tournament->save()) throw new HttpException('No se pudo marcar el cuadro como generado.', 409);
            $this->db->commit();
            return $this->tournament($id);
        } catch (\Throwable $e) {
            if ($this->db->isUnderTransaction()) $this->db->rollback();
            throw $e;
        }
    }
    private function tournamentMatchRecord(int $tournamentId, int $matchId): TournamentMatch {
        $match = TournamentMatch::findFirst([
            'conditions' => 'id = :id: AND tournament_id = :tournament:',
            'bind' => ['id' => $matchId, 'tournament' => $tournamentId],
        ]);
        if (!$match) throw new HttpException('El partido no existe en este torneo.', 404);
        return $match;
    }
    private function matchAudit(int $matchId, int $actorId, string $action, int $revision, array $snapshot): void {
        $audit = new TournamentMatchResultAudit();
        $audit->assign([
            'tournament_match_id' => $matchId,
            'actor_user_id' => $actorId,
            'action' => $action,
            'revision' => $revision,
            'audit_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        if (!$audit->save()) throw new HttpException('No se pudo guardar la trazabilidad del partido.', 409);
    }
    private function matchOperator(TournamentMatch $match, int $userId): User {
        $user = User::findFirst($userId);
        if (!$user || !in_array((string)$user->role, ['admin', 'referee'], true)) throw new HttpException('Usuario sin permisos para operar este partido.', 403);
        if ($user->role === 'referee' && (int)$match->referee_user_id !== $userId) throw new HttpException('Este partido no está asignado a su cuenta de árbitro.', 403);
        return $user;
    }
    private function activeMatchReferee(TournamentMatch $match, int $userId): User {
        $user = $this->matchOperator($match, $userId);
        if ($user->role !== 'referee') throw new HttpException('Solo el árbitro asignado puede registrar incidencias durante el partido.', 403);
        if ($match->workflow_status !== 'in_progress') throw new HttpException('Las incidencias solo se pueden modificar mientras el partido está en juego.', 409);
        return $user;
    }
    public function saveMatchIncident(int $tournamentId, int $matchId, int $actorId, array $input, int $incidentId = 0): array {
        $this->startWrite();
        try {
            $match = $this->tournamentMatchRecord($tournamentId, $matchId);
            $this->activeMatchReferee($match, $actorId);
            $playerId = $this->integer($input['player_id'] ?? null, 'player_id', 1, PHP_INT_MAX);
            $player = $this->record('players', $playerId);
            $teamId = (int)$player->team_id;
            if (!in_array($teamId, [(int)$match->home_team_id, (int)$match->away_team_id], true)) throw new HttpException('El jugador no pertenece a uno de los equipos del partido.', 422, ['player_id' => 'Seleccione un jugador participante.']);
            $minute = $this->integer($input['minute'] ?? null, 'minute', 0, 150);
            $cardType = (string)($input['card_type'] ?? '');
            if (!in_array($cardType, ['yellow', 'red'], true)) throw new HttpException('Tipo de tarjeta inválido.', 422, ['card_type' => 'Seleccione una tarjeta amarilla o roja.']);
            $description = trim((string)($input['description'] ?? ''));
            if ($description === '' || mb_strlen($description) > 500) throw new HttpException('Describa la incidencia (máximo 500 caracteres).', 422, ['description' => 'La descripción es obligatoria y admite hasta 500 caracteres.']);
            $incidentDate = substr((string)$match->scheduled_at, 0, 10);
            $suspensionDate = null;
            if ($cardType === 'red') {
                $suspensionDate = $this->date((string)($input['suspension_date'] ?? ''), 'suspension_date')->format('Y-m-d');
                if ($suspensionDate < $incidentDate) throw new HttpException('La suspensión no puede ser anterior a la incidencia.', 422, ['suspension_date' => 'Seleccione una fecha igual o posterior al partido.']);
            }
            $incident = $incidentId ? Incident::findFirst([
                'conditions' => 'id = :id: AND tournament_match_id = :match:',
                'bind' => ['id' => $incidentId, 'match' => $matchId],
            ]) : new Incident();
            if (!$incident) throw new HttpException('La incidencia no existe en este partido.', 404);
            $before = $incidentId ? $incident->toArray() : null;
            $incident->assign([
                'player_id' => $playerId,
                'description' => $description,
                'card_type' => $cardType,
                'incident_date' => $incidentDate,
                'suspension_date' => $suspensionDate,
                'tournament_match_id' => $matchId,
                'team_id' => $teamId,
                'minute' => $minute,
            ]);
            if (!$incident->save()) throw new HttpException('No se pudo guardar la incidencia: ' . implode('; ', array_map('strval', $incident->getMessages())), 409);
            $this->matchAudit($matchId, $actorId, $incidentId ? 'incident_updated' : 'incident_created', (int)$match->result_revision, ['before' => $before, 'after' => $incident->toArray()]);
            $this->db->commit();
            return $incident->toArray();
        } catch (\Throwable $e) {
            if ($this->db->isUnderTransaction()) $this->db->rollback();
            throw $e;
        }
    }
    public function deleteMatchIncident(int $tournamentId, int $matchId, int $actorId, int $incidentId): void {
        $this->startWrite();
        try {
            $match = $this->tournamentMatchRecord($tournamentId, $matchId);
            $this->activeMatchReferee($match, $actorId);
            $incident = Incident::findFirst([
                'conditions' => 'id = :id: AND tournament_match_id = :match:',
                'bind' => ['id' => $incidentId, 'match' => $matchId],
            ]);
            if (!$incident) throw new HttpException('La incidencia no existe en este partido.', 404);
            $snapshot = $incident->toArray();
            if (!$incident->delete()) throw new HttpException('No se pudo eliminar la incidencia.', 409);
            $this->matchAudit($matchId, $actorId, 'incident_deleted', (int)$match->result_revision, $snapshot);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isUnderTransaction()) $this->db->rollback();
            throw $e;
        }
    }
    private function clearMatchResultRecords(int $matchId): array {
        $snapshot = [];
        foreach ([
            'period_scores' => TournamentMatchPeriodScore::class,
            'player_goals' => TournamentMatchGoal::class,
            'goal_events' => TournamentMatchGoalEvent::class,
            'penalty_kicks' => TournamentMatchPenalty::class,
        ] as $key => $class) {
            $records = $class::find(['conditions' => 'tournament_match_id = :match:', 'bind' => ['match' => $matchId]]);
            $snapshot[$key] = $records->toArray();
            foreach ($records as $record) if (!$record->delete()) throw new HttpException('No se pudo preparar la actualización del resultado.', 409);
        }
        return $snapshot;
    }
    public function startMatch(int $tournamentId, int $matchId, int $actorId): array {
        $this->startWrite();
        try {
            $match = $this->tournamentMatchRecord($tournamentId, $matchId);
            $this->matchOperator($match, $actorId);
            if ($match->workflow_status !== 'scheduled' || !$match->scheduled_at || !$match->referee_user_id) throw new HttpException('Solo se puede iniciar un partido programado con árbitro asignado.', 409);
            $match->workflow_status = 'in_progress';
            $match->started_at = date('Y-m-d H:i:s');
            if (!$match->save()) throw new HttpException('No se pudo iniciar el partido.', 409);
            $this->matchAudit($matchId, $actorId, 'started', (int)$match->result_revision, ['workflow_status' => 'in_progress']);
            $this->db->commit();
            return $this->tournament($tournamentId);
        } catch (\Throwable $e) { if ($this->db->isUnderTransaction()) $this->db->rollback(); throw $e; }
    }
    public function recordMatchGoal(int $tournamentId, int $matchId, int $actorId, array $input): array {
        $this->startWrite();
        try {
            $match = $this->tournamentMatchRecord($tournamentId, $matchId);
            $this->matchOperator($match, $actorId);
            if ($match->workflow_status !== 'in_progress') throw new HttpException('Solo se pueden registrar goles mientras el partido está en juego.', 409);
            $playerId = $this->integer($input['player_id'] ?? null, 'player_id', 1, PHP_INT_MAX);
            $teamId = $this->integer($input['team_id'] ?? null, 'team_id', 1, PHP_INT_MAX);
            $minute = $this->integer($input['minute'] ?? null, 'minute', 0, 150);
            $player = $this->record('players', $playerId);
            if ((int)$player->team_id !== $teamId || !in_array($teamId, [(int)$match->home_team_id, (int)$match->away_team_id], true)) {
                throw new HttpException('El jugador debe pertenecer al equipo participante seleccionado.', 422, ['player_id' => 'Seleccione un jugador del equipo que anotó.']);
            }
            $goal = new TournamentMatchGoalEvent();
            $goal->assign([
                'tournament_match_id' => $matchId,
                'player_id' => $playerId,
                'team_id' => $teamId,
                'minute' => $minute,
                'own_goal' => 0,
                'created_by_user_id' => $actorId,
            ]);
            if (!$goal->save()) throw new HttpException('No se pudo guardar el gol: ' . implode('; ', array_map('strval', $goal->getMessages())), 409);
            $this->matchAudit($matchId, $actorId, 'goal_recorded', (int)$match->result_revision, $goal->toArray());
            $this->db->commit();
            return $goal->toArray();
        } catch (\Throwable $e) {
            if ($this->db->isUnderTransaction()) $this->db->rollback();
            throw $e;
        }
    }
    public function scheduleMatch(int $tournamentId, int $matchId, string $dateTime, array $details = [], int $actorId = 0): array {
        $scheduled = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $dateTime);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (!$scheduled || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $scheduled->format('Y-m-d\TH:i') !== $dateTime) {
            throw new HttpException('Fecha y hora inválidas.', 422, ['scheduled_at' => 'Seleccione una fecha y hora válidas.']);
        }
        $venue = trim((string)($details['venue'] ?? ''));
        $refereeId = $this->integer($details['referee_user_id'] ?? null, 'referee_user_id', 1, PHP_INT_MAX);
        $matchdayId = isset($details['matchday_id']) && $details['matchday_id'] !== '' ? $this->integer($details['matchday_id'], 'matchday_id', 1, PHP_INT_MAX) : null;
        if ($venue === '' || mb_strlen($venue) > 150) throw new HttpException('Indique la cancha (máximo 150 caracteres).', 422, ['venue' => 'La cancha es obligatoria y admite hasta 150 caracteres.']);
        $this->startWrite();
        try {
            $this->tournamentRecord($tournamentId);
            $match = $this->tournamentMatchRecord($tournamentId, $matchId);
            if (!in_array((string)$match->workflow_status, ['unprogrammed', 'scheduled'], true) || $match->status !== 'pending' || !$match->home_team_id || !$match->away_team_id) throw new HttpException('Solo se puede programar un partido que no haya comenzado ni tenga resultado.', 409);
            $referee = User::findFirst(['conditions' => "id = :id: AND role = 'referee'", 'bind' => ['id' => $refereeId]]);
            if (!$referee) throw new HttpException('El usuario asignado debe tener el rol de árbitro.', 422, ['referee_user_id' => 'Seleccione un árbitro válido.']);
            if ($matchdayId !== null) $this->record('matchdays', $matchdayId);
            $normalized = $scheduled->format('Y-m-d H:i:s');
            $teams = [(int)$match->home_team_id, (int)$match->away_team_id];
            foreach (TournamentMatch::find([
                'conditions' => 'tournament_id = :tournament: AND id != :id: AND scheduled_at = :scheduled:',
                'bind' => ['tournament' => $tournamentId, 'id' => $matchId, 'scheduled' => $normalized],
            ]) as $other) {
                if ($other->status === 'bye') continue;
                if (in_array((int)$other->home_team_id, $teams, true) || in_array((int)$other->away_team_id, $teams, true)) {
                    throw new HttpException('Un equipo no puede jugar dos partidos a la misma hora.', 409, ['scheduled_at' => 'Cambie la hora para evitar partidos simultáneos.']);
                }
                if ($other->venue && mb_strtolower(trim((string)$other->venue)) === mb_strtolower($venue)) throw new HttpException('La cancha ya está ocupada a esa hora.', 409, ['venue' => 'Cambie la hora o la cancha.']);
                if ((int)$other->referee_user_id === $refereeId) throw new HttpException('El árbitro ya tiene un partido a esa hora.', 409, ['referee_user_id' => 'Seleccione otro horario o árbitro.']);
            }
            $oldSchedule = ['scheduled_at' => $match->scheduled_at, 'venue' => $match->venue, 'referee_user_id' => $match->referee_user_id, 'matchday_id' => $match->matchday_id];
            $match->scheduled_at = $normalized;
            $match->venue = $venue;
            $match->referee_user_id = $refereeId;
            $match->matchday_id = $matchdayId;
            $match->workflow_status = 'scheduled';
            if (!$match->save()) throw new HttpException('No se pudo programar el partido.', 409);
            if ($actorId) $this->matchAudit($matchId, $actorId, 'scheduled', (int)$match->result_revision, ['before' => $oldSchedule, 'after' => ['scheduled_at' => $normalized, 'venue' => $venue, 'referee_user_id' => $refereeId, 'matchday_id' => $matchdayId]]);
            $this->db->commit();
            return $match->toArray();
        } catch (\Throwable $e) {
            if ($this->db->isUnderTransaction()) $this->db->rollback();
            throw $e;
        }
    }
    public function recordMatchResult(int $tournamentId, int $matchId, array $input, bool $exceptionalCorrection = false, bool $revisePending = false): array {
        if ($exceptionalCorrection && $revisePending) throw new HttpException('Operación de resultado ambigua.', 422);
        $this->startWrite();
        try {
            $tournament = $this->tournamentRecord($tournamentId);
            $match = $this->tournamentMatchRecord($tournamentId, $matchId);
            $actorId = $this->integer($input['actor_user_id'] ?? null, 'actor_user_id', 1, PHP_INT_MAX);
            $operator = $this->matchOperator($match, $actorId);
            if ($exceptionalCorrection) {
                if ($operator->role !== 'admin' || $match->workflow_status !== 'confirmed') throw new HttpException('Solo un administrador puede corregir un resultado confirmado.', 403);
                $next = TournamentMatch::findFirst([
                    'conditions' => 'tournament_id = :tournament: AND round_number = :round: AND match_number = :number:',
                    'bind' => ['tournament' => $tournamentId, 'round' => (int)$match->round_number + 1, 'number' => (int)ceil((int)$match->match_number / 2)],
                ]);
                if ($next && ($next->status !== 'pending' || !in_array((string)$next->workflow_status, ['unprogrammed', 'scheduled'], true))) throw new HttpException('No se puede corregir: el partido de la fase siguiente ya comenzó o fue confirmado.', 409);
                $snapshot = ['match' => $match->toArray()] + $this->clearMatchResultRecords($matchId);
                if ($next) {
                    $slot = (int)$match->match_number % 2 ? 'home_team_id' : 'away_team_id';
                    if ((int)$next->$slot === (int)$match->winner_team_id) $next->$slot = null;
                    if (!$next->save()) throw new HttpException('No se pudo retirar temporalmente el clasificado anterior.', 409);
                }
                $match->winner_team_id = null;
                $match->first_kicker_team_id = null;
                $match->confirmed_at = null;
                $match->confirmed_by_user_id = null;
                $match->status = 'pending';
                $match->workflow_status = 'in_progress';
                $match->result_revision = (int)$match->result_revision + 1;
                if (!$match->save()) throw new HttpException('No se pudo abrir la corrección del resultado.', 409);
                $tournament->status = 'in_progress';
                if (!$tournament->save()) throw new HttpException('No se pudo actualizar el estado del torneo.', 409);
                $this->matchAudit($matchId, $actorId, 'exceptional_correction', (int)$match->result_revision, $snapshot);
            } elseif ($revisePending) {
                if ($match->workflow_status !== 'awaiting_confirmation' || $match->status !== 'completed') throw new HttpException('Solo se puede editar un resultado pendiente de confirmación.', 409);
                $snapshot = ['match' => $match->toArray()] + $this->clearMatchResultRecords($matchId);
                $match->winner_team_id = null;
                $match->first_kicker_team_id = null;
                $match->status = 'pending';
                $match->workflow_status = 'in_progress';
                if (!$match->save()) throw new HttpException('No se pudo abrir la edición del resultado.', 409);
                $this->matchAudit($matchId, $actorId, 'result_revised', (int)$match->result_revision, $snapshot);
            }
            if ($match->workflow_status !== 'in_progress' || $match->status !== 'pending' || !$match->home_team_id || !$match->away_team_id) throw new HttpException('El partido debe estar en juego para registrar el resultado.', 409);
            if (!$match->scheduled_at) throw new HttpException('Programe el partido antes de registrar el resultado.', 409);
            if (!$tournament->extra_time_periods || !$tournament->extra_time_period_minutes || !$tournament->penalty_attempts_per_team || $tournament->penalty_sudden_death === null) throw new HttpException('Configure las reglas de desempate antes de registrar resultados.', 409);
            if (!isset($input['period_scores']) || !is_array($input['period_scores']) || !array_is_list($input['period_scores']) || !$input['period_scores']) throw new HttpException('Ingrese el marcador reglamentario.', 422, ['period_scores' => 'Ingrese los goles de ambos equipos.']);
            $periodScores = [];
            foreach ($input['period_scores'] as $index => $period) {
                if (!is_array($period)) throw new HttpException('Marcador de período inválido.', 422);
                $number = $this->integer($period['period_number'] ?? null, 'period_scores', 0, (int)$tournament->extra_time_periods);
                if ($number !== $index || isset($periodScores[$number])) throw new HttpException('Los períodos deben enviarse en orden y sin repetir.', 422);
                $periodScores[$number] = [
                    'home_score' => $this->integer($period['home_score'] ?? null, 'period_scores', 0, 2147483647),
                    'away_score' => $this->integer($period['away_score'] ?? null, 'period_scores', 0, 2147483647),
                    'home_own_goals' => $this->integer($period['home_own_goals'] ?? 0, 'period_scores', 0, 2147483647),
                    'away_own_goals' => $this->integer($period['away_own_goals'] ?? 0, 'period_scores', 0, 2147483647),
                ];
            }
            if (!isset($periodScores[0])) throw new HttpException('Ingrese el marcador reglamentario.', 422);
            $regulationTied = $periodScores[0]['home_score'] === $periodScores[0]['away_score'];
            if (!$regulationTied && count($periodScores) !== 1) throw new HttpException('El tiempo extra solo se juega si el tiempo reglamentario termina empatado.', 422);
            if ($regulationTied && count($periodScores) !== (int)$tournament->extra_time_periods + 1) throw new HttpException('Registre todos los períodos de tiempo extra configurados.', 422);
            $homeScore = $awayScore = $homeOwnGoals = $awayOwnGoals = 0;
            foreach ($periodScores as $score) {
                $homeScore += $score['home_score']; $awayScore += $score['away_score'];
                $homeOwnGoals += $score['home_own_goals']; $awayOwnGoals += $score['away_own_goals'];
            }
            $homeTeamId = (int)$match->home_team_id;
            $awayTeamId = (int)$match->away_team_id;
            $goalTotals = [$homeTeamId => 0, $awayTeamId => 0];
            $playerGoals = $input['player_goals'] ?? [];
            if (!is_array($playerGoals) || !array_is_list($playerGoals)) throw new HttpException('Lista de goleadores inválida.', 422);
            $normalizedGoals = []; $seenPlayerIds = [];
            foreach ($playerGoals as $entry) {
                if (!is_array($entry)) throw new HttpException('Goleador inválido.', 422);
                $playerId = $this->integer($entry['player_id'] ?? null, 'player_goals', 1, PHP_INT_MAX);
                $goals = $this->integer($entry['goals'] ?? null, 'player_goals', 0, 2147483647);
                if (isset($seenPlayerIds[$playerId])) throw new HttpException('Cada jugador debe aparecer una sola vez en los goles del partido.', 422, ['player_goals' => 'Combine los goles de cada jugador en una sola línea.']);
                $seenPlayerIds[$playerId] = true;
                if (!$goals) continue;
                $player = $this->record('players', $playerId);
                $teamId = (int)$player->team_id;
                if (!isset($goalTotals[$teamId])) throw new HttpException('El goleador no pertenece a uno de los equipos del partido.', 422, ['player_goals' => 'Seleccione un jugador de los equipos participantes.']);
                if ($this->isSuspendedForMatch($playerId, substr((string)$match->scheduled_at, 0, 10), $matchId)) throw new HttpException('Un jugador suspendido no puede figurar como goleador del partido.', 409, ['player_goals' => 'Quite al jugador suspendido del registro de goles.']);
                $goalTotals[$teamId] += $goals;
                $normalizedGoals[] = ['player_id' => $playerId, 'team_id' => $teamId, 'goals' => $goals];
            }
            if ($goalTotals[$homeTeamId] + $awayOwnGoals !== $homeScore || $goalTotals[$awayTeamId] + $homeOwnGoals !== $awayScore) {
                throw new HttpException('El marcador no coincide con los goles individuales y autogoles registrados.', 422, ['player_goals' => 'Ajuste los goles por jugador o los autogoles de cada equipo.']);
            }
            $goalEvents = $input['goal_events'] ?? [];
            if (!is_array($goalEvents) || !array_is_list($goalEvents)) throw new HttpException('Lista de goles inválida.', 422, ['goal_events' => 'Envíe los goles con su minuto.']);
            $eventScorers = []; $eventOwnGoals = [$homeTeamId => 0, $awayTeamId => 0]; $normalizedEvents = [];
            foreach ($goalEvents as $event) {
                if (!is_array($event)) throw new HttpException('Gol inválido.', 422);
                $playerId = $this->integer($event['player_id'] ?? null, 'goal_events', 1, PHP_INT_MAX);
                $minute = $this->integer($event['minute'] ?? null, 'goal_events', 0, 150);
                $ownGoal = $this->integer($event['own_goal'] ?? 0, 'goal_events', 0, 1);
                $player = $this->record('players', $playerId);
                $teamId = (int)$player->team_id;
                if (!in_array($teamId, [$homeTeamId, $awayTeamId], true)) throw new HttpException('El autor del gol debe pertenecer a un equipo del partido.', 422, ['goal_events' => 'Seleccione un jugador de los equipos participantes.']);
                if ($this->isSuspendedForMatch($playerId, substr((string)$match->scheduled_at, 0, 10), $matchId)) throw new HttpException('Un jugador suspendido no puede figurar como autor de un gol.', 409, ['goal_events' => 'Quite al jugador suspendido del registro de goles.']);
                if ($ownGoal) $eventOwnGoals[$teamId]++;
                else $eventScorers[$playerId] = ($eventScorers[$playerId] ?? 0) + 1;
                $normalizedEvents[] = ['player_id' => $playerId, 'team_id' => $teamId, 'minute' => $minute, 'own_goal' => $ownGoal];
            }
            $expectedScorers = [];
            foreach ($normalizedGoals as $goalData) $expectedScorers[(int)$goalData['player_id']] = (int)$goalData['goals'];
            ksort($expectedScorers); ksort($eventScorers);
            if ($eventScorers !== $expectedScorers || $eventOwnGoals[$homeTeamId] !== $homeOwnGoals || $eventOwnGoals[$awayTeamId] !== $awayOwnGoals) {
                throw new HttpException('Cada gol del marcador debe tener jugador y minuto, incluidos los autogoles.', 422, ['goal_events' => 'Revise la lista de goles y sus minutos.']);
            }
            foreach (Incident::find([
                'conditions' => "tournament_match_id = :match: AND card_type = 'red' AND incident_date = :date:",
                'bind' => ['match' => $matchId, 'date' => substr((string)$match->scheduled_at, 0, 10)],
            ]) as $redCard) {
                foreach ($normalizedEvents as $event) if ((int)$event['player_id'] === (int)$redCard->player_id && (int)$event['minute'] >= (int)$redCard->minute) {
                    throw new HttpException('Un jugador expulsado no puede anotar después de la tarjeta roja.', 409, ['goal_events' => 'Revise los minutos de la tarjeta y del gol.']);
                }
            }
            $winnerId = $homeScore > $awayScore ? $homeTeamId : ($awayScore > $homeScore ? $awayTeamId : 0);
            $firstKicker = null;
            $penaltyKicks = [];
            if (!$winnerId) {
                $firstKicker = $this->integer($input['first_kicker_team_id'] ?? null, 'first_kicker_team_id', 1, PHP_INT_MAX);
                if (!in_array($firstKicker, [$homeTeamId, $awayTeamId], true)) throw new HttpException('Seleccione uno de los equipos como primer pateador.', 422);
                $kicks = $input['penalty_kicks'] ?? [];
                if (!is_array($kicks) || !array_is_list($kicks) || !$kicks) throw new HttpException('Ingrese los tiros de la tanda de penales.', 422);
                $attempts = [$homeTeamId => 0, $awayTeamId => 0];
                $penaltyScores = [$homeTeamId => 0, $awayTeamId => 0];
                $initialAttempts = (int)$tournament->penalty_attempts_per_team;
                $suddenDeathStarted = false;
                $winnerId = 0;
                foreach ($kicks as $index => $kick) {
                    if (!is_array($kick)) throw new HttpException('Tiro de penal inválido.', 422);
                    $expectedTeam = $index % 2 === 0 ? $firstKicker : ($firstKicker === $homeTeamId ? $awayTeamId : $homeTeamId);
                    $teamId = $this->integer($kick['team_id'] ?? null, 'penalty_kicks', 1, PHP_INT_MAX);
                    $scored = $this->integer($kick['scored'] ?? null, 'penalty_kicks', 0, 1);
                    if ($teamId !== $expectedTeam) throw new HttpException('Los pateadores deben alternarse durante la tanda.', 422, ['penalty_kicks' => 'Revise el orden de los tiros.']);
                    if ($winnerId) throw new HttpException('La tanda continuó después de que ya había un ganador.', 422);
                    $attempts[$teamId]++;
                    $penaltyScores[$teamId] += $scored;
                    $penaltyKicks[] = ['team_id' => $teamId, 'scored' => $scored];
                    if (!$suddenDeathStarted) {
                        $homeRemaining = max(0, $initialAttempts - $attempts[$homeTeamId]);
                        $awayRemaining = max(0, $initialAttempts - $attempts[$awayTeamId]);
                        if ($penaltyScores[$homeTeamId] > $penaltyScores[$awayTeamId] + $awayRemaining) $winnerId = $homeTeamId;
                        elseif ($penaltyScores[$awayTeamId] > $penaltyScores[$homeTeamId] + $homeRemaining) $winnerId = $awayTeamId;
                        elseif ($attempts[$homeTeamId] >= $initialAttempts && $attempts[$awayTeamId] >= $initialAttempts) {
                            if ($penaltyScores[$homeTeamId] !== $penaltyScores[$awayTeamId]) $winnerId = $penaltyScores[$homeTeamId] > $penaltyScores[$awayTeamId] ? $homeTeamId : $awayTeamId;
                            elseif (!(int)$tournament->penalty_sudden_death) throw new HttpException('La tanda terminó empatada y este torneo no aplica muerte súbita.', 409);
                            else $suddenDeathStarted = true;
                        }
                    } elseif ($attempts[$homeTeamId] === $attempts[$awayTeamId] && $penaltyScores[$homeTeamId] !== $penaltyScores[$awayTeamId]) {
                        $winnerId = $penaltyScores[$homeTeamId] > $penaltyScores[$awayTeamId] ? $homeTeamId : $awayTeamId;
                    }
                }
                if (!$winnerId) throw new HttpException('La tanda debe continuar hasta que exista un ganador.', 422, ['penalty_kicks' => 'Complete los tiros iniciales y, si corresponde, la muerte súbita.']);
            } elseif (!empty($input['penalty_kicks'])) {
                throw new HttpException('No registre penales si el partido ya tuvo un ganador en el juego.', 422);
            }
            foreach ($periodScores as $periodNumber => $score) {
                $period = new TournamentMatchPeriodScore();
                $period->assign(['tournament_match_id' => $matchId, 'period_number' => $periodNumber] + $score);
                if (!$period->save()) throw new HttpException('No se pudo guardar el marcador por período.', 409);
            }
            foreach ($normalizedGoals as $goalData) {
                $goal = new TournamentMatchGoal();
                $goal->assign(['tournament_match_id' => $matchId] + $goalData);
                if (!$goal->save()) throw new HttpException('No se pudo guardar el goleador del partido.', 409);
            }
            foreach ($normalizedEvents as $eventData) {
                $event = new TournamentMatchGoalEvent();
                $event->assign(['tournament_match_id' => $matchId, 'created_by_user_id' => $actorId] + $eventData);
                if (!$event->save()) throw new HttpException('No se pudo guardar el evento de gol.', 409);
            }
            foreach ($penaltyKicks as $index => $kick) {
                $penalty = new TournamentMatchPenalty();
                $penalty->assign(['tournament_match_id' => $matchId, 'kick_number' => $index + 1] + $kick);
                if (!$penalty->save()) throw new HttpException('No se pudo guardar la tanda de penales.', 409);
            }
            $match->winner_team_id = $winnerId;
            $match->first_kicker_team_id = $firstKicker;
            $match->status = 'completed';
            $match->workflow_status = 'awaiting_confirmation';
            $match->result_revision = (int)$match->result_revision + 1;
            if (!$match->save()) throw new HttpException('No se pudo guardar el resultado del partido.', 409);
            $tournament->status = 'in_progress';
            if (!$tournament->save()) throw new HttpException('No se pudo actualizar el estado del torneo.', 409);
            $this->matchAudit($matchId, $actorId, 'result_submitted', (int)$match->result_revision, ['winner_team_id' => $winnerId, 'period_scores' => $periodScores, 'player_goals' => $normalizedGoals, 'goal_events' => $normalizedEvents, 'penalty_kicks' => $penaltyKicks]);
            $this->db->commit();
            return $this->tournament($tournamentId);
        } catch (\Throwable $e) {
            if ($this->db->isUnderTransaction()) $this->db->rollback();
            throw $e;
        }
    }
    public function confirmMatchResult(int $tournamentId, int $matchId, int $actorId): array {
        $this->startWrite();
        try {
            $tournament = $this->tournamentRecord($tournamentId);
            $match = $this->tournamentMatchRecord($tournamentId, $matchId);
            $this->matchOperator($match, $actorId);
            if ($match->workflow_status !== 'awaiting_confirmation' || !$match->winner_team_id) throw new HttpException('El resultado no está pendiente de confirmación.', 409);
            $match->workflow_status = 'confirmed';
            $match->confirmed_at = date('Y-m-d H:i:s');
            $match->confirmed_by_user_id = $actorId;
            if (!$match->save()) throw new HttpException('No se pudo confirmar el resultado.', 409);
            $this->matchAudit($matchId, $actorId, 'confirmed', (int)$match->result_revision, ['winner_team_id' => (int)$match->winner_team_id]);
            $next = TournamentMatch::findFirst([
                'conditions' => 'tournament_id = :tournament: AND round_number = :round: AND match_number = :number:',
                'bind' => ['tournament' => $tournamentId, 'round' => (int)$match->round_number + 1, 'number' => (int)ceil((int)$match->match_number / 2)],
            ]);
            if ($next) {
                $slot = (int)$match->match_number % 2 ? 'home_team_id' : 'away_team_id';
                if ($next->$slot !== null && (int)$next->$slot !== (int)$match->winner_team_id) throw new HttpException('El partido siguiente ya contiene otro clasificado; no se puede avanzar este resultado.', 409);
                $next->$slot = (int)$match->winner_team_id;
                if (!$next->save()) throw new HttpException('No se pudo avanzar al ganador en el cuadro.', 409);
                $tournament->status = 'in_progress';
            } else $tournament->status = 'finished';
            if (!$tournament->save()) throw new HttpException('No se pudo actualizar el estado del torneo.', 409);
            $this->db->commit();
            return $this->tournament($tournamentId);
        } catch (\Throwable $e) {
            if ($this->db->isUnderTransaction()) $this->db->rollback();
            throw $e;
        }
    }
    private function required(array $data, array $fields, array $lengths = []): void {
        $validation = new Validation();
        foreach ($fields as $field) $validation->add($field, new PresenceOf(['message' => 'Este campo es obligatorio.']));
        foreach ($lengths as $field => $max) $validation->add($field, new StringLength(['max' => $max, 'messageMaximum' => "Máximo {$max} caracteres."]));
        $errors = [];
        foreach ($validation->validate($data) as $message) $errors[(string)$message->getField()] = (string)$message;
        if ($errors) throw new HttpException('Revise los campos del formulario.', 422, $errors);
    }
    private function date(string $value, string $field): \DateTimeImmutable {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new HttpException('Fecha inválida.', 422, [$field => 'Ingrese una fecha válida.']);
        return $date;
    }
    private function integer(mixed $value, string $field, int $min, int $max): int {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < $min || (int)$value > $max) throw new HttpException('Número inválido.', 422, [$field => "Ingrese un entero entre {$min} y {$max}."]);
        return (int)$value;
    }
    private function unique(string $entity, string $field, mixed $value, int $id): void {
        $class = $this->model($entity);
        foreach ($class::find() as $row) {
            if ((int)$row->id !== $id && mb_strtolower((string)$row->$field) === mb_strtolower((string)$value)) throw new HttpException('Ya existe un registro con ese valor.', 422, [$field => 'Este valor ya está registrado.']);
        }
    }
    public function isSuspended(int $playerId, string $date): bool {
        return Incident::count(['conditions' => 'player_id = :p: AND card_type = :card: AND suspension_date = :date:', 'bind' => ['p' => $playerId, 'card' => 'red', 'date' => $date]]) > 0;
    }
    private function isSuspendedForMatch(int $playerId, string $date, int $matchId): bool {
        return Incident::count([
            'conditions' => 'player_id = :player: AND card_type = :card: AND suspension_date = :date: AND (tournament_match_id IS NULL OR tournament_match_id != :match:)',
            'bind' => ['player' => $playerId, 'card' => 'red', 'date' => $date, 'match' => $matchId],
        ]) > 0;
    }
    public function save(string $entity, array $input, int $id = 0, bool $trustedPhoto = false, bool $removePhoto = false): array {
        $class = $this->model($entity);
        $this->startWrite();
        try {
            $row = $id ? $this->record($entity, $id) : new $class();
            if ($entity === 'incidents' && $id && $row->tournament_match_id) throw new HttpException('Las incidencias de partido solo se modifican desde el partido y con el árbitro asignado.', 403);
            $data = array_intersect_key($input, array_flip(self::FIELDS[$entity]));
            foreach ($data as $key => $value) {
                if (!is_scalar($value) && $value !== null) throw new HttpException('Valor inválido en el formulario.', 422, [$key => 'Valor inválido.']);
                if (is_string($value)) $data[$key] = trim($value);
            }
            if ($entity === 'teams') {
                $this->required($data, ['name'], ['name' => 100]);
                $this->unique('teams', 'name', $data['name'], $id);
                $data['active'] = $this->integer($data['active'] ?? 1, 'active', 0, 1);
            } elseif ($entity === 'players') {
                $this->required($data, ['first_name', 'last_name', 'birth_date', 'team_id'], ['first_name' => 100, 'last_name' => 100, 'position' => 50]);
                foreach (['first_name', 'last_name'] as $name) {
                    if (!preg_match('/^[\p{L}\p{M}]+(?:[ ]+[\p{L}\p{M}]+|[\x{0027}\x{2019}\x{002D}][\p{L}\p{M}]+)*$/u', $data[$name])) throw new HttpException('Use letras, espacios, guiones o apóstrofos en el nombre.', 422, [$name => 'Use letras, espacios, guiones o apóstrofos.']);
                }
                $birth = $this->date($data['birth_date'], 'birth_date');
                $reference = $this->date($this->settings['age_reference_date'], 'age_reference_date');
                $today = $this->date(date('Y-m-d'), 'birth_date');
                $age = $birth->diff($reference)->y;
                if ($birth > $today || $birth > $reference || $age < $this->settings['age_min'] || $age > $this->settings['age_max']) throw new HttpException('La edad está fuera de la categoría infantil.', 422, ['birth_date' => "Edad permitida: {$this->settings['age_min']} a {$this->settings['age_max']} años al {$reference->format('d/m/Y')}; no se admiten fechas futuras."]);
                $data['team_id'] = $this->integer($data['team_id'], 'team_id', 1, PHP_INT_MAX);
                $team = $this->record('teams', $data['team_id']);
                if (!(int)$team->active && (!$id || (int)$row->team_id !== $data['team_id'])) throw new HttpException('El equipo está inactivo.');
                if ($id && (int)$row->team_id !== $data['team_id'] && (Goal::count(['player_id = :p:', 'bind' => ['p' => $id]]) || TournamentMatchGoal::count(['player_id = :p:', 'bind' => ['p' => $id]]) || Incident::count(['player_id = :p:', 'bind' => ['p' => $id]]))) throw new HttpException('Un jugador con historial debe conservar su equipo durante este torneo.', 409);
                $data['shirt_number'] = isset($data['shirt_number']) && $data['shirt_number'] !== '' ? $this->integer($data['shirt_number'], 'shirt_number', 1, 99) : null;
                $data['position'] = $data['position'] ?? null;
                if ($trustedPhoto) $data['photo_path'] = $input['photo_path'];
                elseif ($removePhoto) $data['photo_path'] = 'images/player-placeholder.svg';
                elseif (!$id) $data['photo_path'] = 'images/player-placeholder.svg';
            } elseif ($entity === 'matchdays') {
                $this->required($data, ['number', 'game_date']);
                $data['number'] = $this->integer($data['number'], 'number', 1, 100);
                $this->date($data['game_date'], 'game_date');
                $this->unique('matchdays', 'number', $data['number'], $id);
                $this->unique('matchdays', 'game_date', $data['game_date'], $id);
                if ($id) foreach (Goal::find(['matchday_id = :m:', 'bind' => ['m' => $id]]) as $goal) {
                    if ($this->isSuspended((int)$goal->player_id, $data['game_date'])) throw new HttpException('La nueva fecha entra en conflicto con goles de un jugador suspendido.', 409);
                }
                if ($id && $data['game_date'] !== $row->game_date && Incident::count(['suspension_date = :d:', 'bind' => ['d' => $row->game_date]])) throw new HttpException('Ajuste primero las suspensiones asociadas antes de cambiar la fecha de esta jornada.', 409);
            } elseif ($entity === 'goals') {
                $this->required($data, ['player_id', 'matchday_id', 'goals']);
                $data['player_id'] = $this->integer($data['player_id'], 'player_id', 1, PHP_INT_MAX);
                $data['matchday_id'] = $this->integer($data['matchday_id'], 'matchday_id', 1, PHP_INT_MAX);
                $data['goals'] = $this->integer($data['goals'], 'goals', 0, 20);
                $this->record('players', $data['player_id']);
                $matchday = $this->record('matchdays', $data['matchday_id']);
                if ($this->isSuspended($data['player_id'], $matchday->game_date)) throw new HttpException('El jugador está suspendido para esa jornada.', 409);
                $duplicate = Goal::findFirst(['conditions' => 'player_id = :p: AND matchday_id = :m: AND id != :id:', 'bind' => ['p' => $data['player_id'], 'm' => $data['matchday_id'], 'id' => $id]]);
                if ($duplicate) throw new HttpException('Ya hay goles registrados para este jugador y jornada. Edite el registro existente.', 409);
            } elseif ($entity === 'incidents') {
                $this->required($data, ['player_id', 'description', 'card_type', 'incident_date'], ['description' => 500]);
                $data['player_id'] = $this->integer($data['player_id'], 'player_id', 1, PHP_INT_MAX);
                $this->record('players', $data['player_id']);
                $this->date($data['incident_date'], 'incident_date');
                if (!in_array($data['card_type'], ['yellow', 'red'], true)) throw new HttpException('Tipo de tarjeta inválido.');
                if ($data['card_type'] === 'yellow') $data['suspension_date'] = null;
                else {
                    $this->required($data, ['suspension_date']);
                    $suspension = $this->date($data['suspension_date'], 'suspension_date');
                    if ($suspension < $this->date($data['incident_date'], 'incident_date')) throw new HttpException('La suspensión no puede ser anterior a la incidencia.');
                    foreach (Goal::find(['player_id = :p:', 'bind' => ['p' => $data['player_id']]]) as $goal) {
                        if ($this->record('matchdays', (int)$goal->matchday_id)->game_date === $data['suspension_date']) throw new HttpException('Ya hay goles registrados en la fecha de suspensión. Corrija ese registro primero.', 409);
                    }
                }
            }
            $oldPhoto = $entity === 'players' ? $row->photo_path : null;
            $row->assign($data);
            if (!$row->save()) throw new HttpException('No se pudo guardar: ' . implode('; ', array_map('strval', $row->getMessages())));
            $this->db->commit();
            if (($trustedPhoto || $removePhoto) && $oldPhoto !== $row->photo_path) $this->removePhotoIfUnreferenced($oldPhoto);
            return $row->toArray();
        } catch (\Throwable $e) {
            if ($this->db->isUnderTransaction()) $this->db->rollback();
            throw $e;
        }
    }
    private function removePhotoIfUnreferenced(?string $path): void {
        if (!$path || !preg_match('~^uploads/players/[a-f0-9]{32}\.jpg$~', $path)) return;
        if ((int)Player::count(['conditions' => 'photo_path = :path:', 'bind' => ['path' => $path]]) === 0) PhotoStorage::remove($path);
    }
    public function delete(string $entity, int $id): void {
        $this->model($entity);
        $this->startWrite();
        try {
            $row = $this->record($entity, $id);
            if ($entity === 'incidents' && $row->tournament_match_id) throw new HttpException('Las incidencias de partido solo se eliminan desde el partido y con el árbitro asignado.', 403);
            $blocked = match($entity) {
                'teams' => Player::count(['team_id = :i:', 'bind' => ['i' => $id]]) + TournamentTeam::count(['team_id = :i:', 'bind' => ['i' => $id]]) + TournamentMatch::count(['home_team_id = :home: OR away_team_id = :away:', 'bind' => ['home' => $id, 'away' => $id]]),
                'players' => Goal::count(['player_id = :i:', 'bind' => ['i' => $id]]) + TournamentMatchGoal::count(['player_id = :i:', 'bind' => ['i' => $id]]) + Incident::count(['player_id = :i:', 'bind' => ['i' => $id]]),
                'matchdays' => Goal::count(['matchday_id = :i:', 'bind' => ['i' => $id]]) + Incident::count(['suspension_date = :d:', 'bind' => ['d' => $row->game_date]]),
                default => 0,
            };
            if ($blocked) throw new HttpException('Este registro tiene información asociada. Edite sus datos o retire primero las asociaciones.', 409);
            if (!$row->delete()) throw new HttpException('No se pudo eliminar el registro.', 409);
            $this->db->commit();
            if ($entity === 'players') $this->removePhotoIfUnreferenced($row->photo_path);
        } catch (\Throwable $e) { if ($this->db->isUnderTransaction()) $this->db->rollback(); throw $e; }
    }
    private function decoratePlayer(array $player, array $teams): array {
        $team = current(array_filter($teams, fn($t) => (int)$t['id'] === (int)$player['team_id']));
        $player['name'] = $player['first_name'] . ' ' . $player['last_name'];
        $player['team_name'] = $team ? $team['name'] : '';
        $player['age'] = $this->date($player['birth_date'], 'birth_date')->diff($this->date($this->settings['age_reference_date'], 'age_reference_date'))->y;
        return $player;
    }
    public function report(string $kind, array $filters): array {
        $state = $this->state();
        $team = (int)($filters['team_id'] ?? 0);
        if ($team) $this->record('teams', $team);
        $matchday = (int)($filters['matchday_id'] ?? 0);
        if ($matchday) $this->record('matchdays', $matchday);
        if ($kind === 'referee' && !$matchday) throw new HttpException('Seleccione una jornada para verificar suspensiones.');
        $players = array_values(array_filter($state['players'], fn($p) => !$team || (int)$p['team_id'] === $team));
        if ($kind === 'incidents') {
            $playerId = (int)($filters['player_id'] ?? 0);
            if ($playerId) $this->record('players', $playerId);
            $from = (string)($filters['date_from'] ?? ''); $to = (string)($filters['date_to'] ?? '');
            if ($from) $this->date($from, 'date_from'); if ($to) $this->date($to, 'date_to');
            if ($from && $to && $from > $to) throw new HttpException('La fecha inicial debe ser anterior a la final.');
            $card = (string)($filters['card_type'] ?? '');
            if ($card && !in_array($card, ['yellow', 'red'], true)) throw new HttpException('Filtro de tarjeta inválido.');
            $rows = [];
            foreach ($state['incidents'] as $incident) {
                $p = current(array_filter($players, fn($p) => (int)$p['id'] === (int)$incident['player_id']));
                if (!$p || ($playerId && (int)$incident['player_id'] !== $playerId) || ($card && $incident['card_type'] !== $card) || ($from && $incident['incident_date'] < $from) || ($to && $incident['incident_date'] > $to)) continue;
                $player = $this->decoratePlayer($p, $state['teams']);
                $rows[] = $incident + ['player_name' => $player['name'], 'team_name' => $player['team_name']];
            }
            usort($rows, fn($a, $b) => strcmp($b['incident_date'], $a['incident_date']) ?: ((int)$b['id'] <=> (int)$a['id']));
            return ['rows' => $rows, 'filters' => $filters];
        }
        $rows = [];
        foreach ($players as $p) {
            $p = $this->decoratePlayer($p, $state['teams']);
            if ($kind === 'referee') $p['suspended'] = $this->isSuspended((int)$p['id'], $this->record('matchdays', $matchday)->game_date);
            else {
                $p['total_goals'] = array_sum(array_map(fn($g) => (int)$g['goals'], array_filter($state['goals'], fn($g) => (int)$g['player_id'] === (int)$p['id'] && (!$matchday || (int)$g['matchday_id'] === $matchday))));
                if (!$matchday) $p['total_goals'] += array_sum(array_map(fn($g) => (int)$g['goals'], array_filter($state['tournament_match_goals'], fn($g) => (int)$g['player_id'] === (int)$p['id'])));
            }
            $rows[] = $p;
        }
        if ($kind === 'scorers') usort($rows, fn($a, $b) => ($b['total_goals'] <=> $a['total_goals']) ?: strcmp($a['name'], $b['name']));
        else usort($rows, fn($a, $b) => strcmp($a['team_name'], $b['team_name']) ?: strcmp($a['name'], $b['name']));
        return ['rows' => $rows, 'filters' => $filters];
    }
    public function player(int $id): array {
        $state = $this->state();
        $p = $this->decoratePlayer($this->record('players', $id)->toArray(), $state['teams']);
        $p['incidents'] = array_values(array_filter($state['incidents'], fn($i) => (int)$i['player_id'] === $id));
        $p['history'] = array_map(function($m) use($state, $id) {
            $m['goals'] = array_sum(array_map(fn($g) => (int)$g['goals'], array_filter($state['goals'], fn($g) => (int)$g['player_id'] === $id && (int)$g['matchday_id'] === (int)$m['id'])));
            $m['suspended'] = $this->isSuspended($id, $m['game_date']);
            return $m;
        }, $state['matchdays']);
        $p['match_history'] = array_values(array_filter($state['tournament_match_goals'], fn($g) => (int)$g['player_id'] === $id));
        $p['total_goals'] = array_sum(array_column($p['history'], 'goals')) + array_sum(array_column($p['match_history'], 'goals'));
        return $p;
    }
}
