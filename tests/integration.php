<?php
declare(strict_types=1);
// Isolated SQLite database. The application itself defaults to MySQL.
if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "Pruebas: se necesita pdo_sqlite (incluido en Docker).\n"); exit(1); }
$file = sys_get_temp_dir() . '/cantera-test-' . bin2hex(random_bytes(8)) . '.sqlite';
putenv('DB_DRIVER=sqlite'); putenv('DB_NAME=' . $file);
require dirname(__DIR__) . '/scripts/migrate.php';
require dirname(__DIR__) . '/scripts/seed.php';
Phalcon\Di\Di::setDefault($di);
$service = $di->getShared('tournament');
$checks = 0;
function check(bool $condition, string $name): void { global $checks; if (!$condition) throw new RuntimeException('FAIL: ' . $name); $checks++; echo "PASS: {$name}\n"; }
function rejected(callable $fn, string $name): void { try { $fn(); } catch (App\Exceptions\HttpException $e) { check($e->status >= 400, $name); return; } throw new RuntimeException('FAIL: se aceptó ' . $name); }
$tieRules = ['extra_time_periods' => 2, 'extra_time_period_minutes' => 10, 'penalty_attempts_per_team' => 5, 'penalty_sudden_death' => 1];
try {
    $photoDirectory = sys_get_temp_dir() . '/cantera-photo-permissions-' . bin2hex(random_bytes(6));
    if (!mkdir($photoDirectory, 0755)) throw new RuntimeException('No se pudo preparar la prueba de permisos de fotografía.');
    chmod($photoDirectory, 0555);
    App\Services\PhotoStorage::ensureWritableDirectory($photoDirectory);
    $photoProbe = $photoDirectory . '/write-check';
    check(file_put_contents($photoProbe, 'ok') === 2, 'Directorio de fotografías recupera permiso de escritura');
    unlink($photoProbe); chmod($photoDirectory, 0755); rmdir($photoDirectory);
    $state = $service->state();
    check(count($state['teams']) === 4 && count($state['players']) === 20 && count($state['matchdays']) === 5, 'Datos de demostración completos');
    check($state['tournaments'] === [] && $state['tournament_teams'] === [] && $state['tournament_matches'] === [], 'Migraciones aditivas sin tocar el catálogo existente');
    $referee = $service->report('referee', ['matchday_id' => 3]);
    check(count(array_filter($referee['rows'], fn($p) => $p['suspended'])) === 3, 'Tres suspensiones en jornada 3');
    check(count(array_filter($service->report('referee', ['matchday_id' => 4])['rows'], fn($p) => $p['suspended'])) === 0, 'Suspensión solo en la fecha indicada');
    $scores = $service->report('scorers', [])['rows'];
    check($scores[0]['total_goals'] === 10 && count(array_unique(array_column($scores, 'total_goals'))) >= 5, 'Totales y orden de goleadores');
    check(count($service->report('incidents', ['team_id' => 1, 'card_type' => 'red'])['rows']) === 1, 'Filtro de incidencias por equipo y tarjeta');
    check(count($service->report('incidents', ['team_id' => 1, 'player_id' => 4])['rows']) === 1, 'Incidencias de un jugador o todos los del equipo');
    check(count($service->report('incidents', ['date_from' => '2026-10-10', 'date_to' => '2026-10-10'])['rows']) === 5, 'Rango de fechas inclusivo');
    check($service->player(2)['history'][2]['suspended'] === true && $service->player(2)['total_goals'] === 9, 'Ficha completa e historial');
    rejected(fn() => $service->save('goals', ['player_id' => 2, 'matchday_id' => 3, 'goals' => 1]), 'Bloqueo de goles por suspensión');
    rejected(fn() => $service->save('goals', ['player_id' => 1, 'matchday_id' => 1, 'goals' => 2]), 'Un registro de goles por jugador y jornada');
    rejected(fn() => $service->save('incidents', ['player_id' => 1, 'description' => 'Prueba', 'card_type' => 'red', 'incident_date' => '2026-10-10']), 'Roja requiere suspensión');
    rejected(fn() => $service->save('incidents', ['player_id' => 1, 'description' => 'Prueba', 'card_type' => 'red', 'incident_date' => '2026-10-10', 'suspension_date' => '2026-10-03']), 'Suspensión anterior a incidencia');
    rejected(fn() => $service->save('incidents', ['player_id' => 1, 'description' => 'Prueba', 'card_type' => 'red', 'incident_date' => '2026-10-03', 'suspension_date' => '2026-10-10']), 'Suspensión incompatible con goles existentes');
    rejected(fn() => $service->save('teams', ['name' => 'LEONES DE PETAPA', 'active' => 1]), 'Nombre de equipo único sin distinción de mayúsculas');
    rejected(fn() => $service->delete('teams', 1), 'Protección de equipo con jugadores');
    rejected(fn() => $service->delete('players', 1), 'Protección del historial del jugador');
    rejected(fn() => $service->delete('matchdays', 3), 'Protección de jornada con suspensiones');
    rejected(fn() => $service->save('matchdays', ['number' => 3, 'game_date' => '2026-11-07'], 3), 'Cambio de fecha con suspensiones exige ajuste previo');
    rejected(fn() => $service->save('matchdays', ['number' => 6, 'game_date' => '2026-02-30']), 'Fecha inexistente');
    rejected(fn() => $service->save('players', ['first_name'=>'Juan1', 'last_name'=>'Pérez','birth_date'=>'2015-04-02','team_id'=>1]), 'Nombre con caracteres inválidos');
    rejected(fn() => $service->save('players', ['first_name'=>'Juan', 'last_name'=>'Pérez','birth_date'=>'2000-04-02','team_id'=>1]), 'Edad fuera de la categoría');
    rejected(fn() => $service->save('players', ['first_name'=>'Jugador','last_name'=>'Futuro','birth_date'=>date('Y-m-d', strtotime('+1 day')),'team_id'=>1]), 'Fecha de nacimiento futura');
    $p = $state['players'][0]; $p['team_id'] = 2;
    rejected(fn() => $service->save('players', $p, 1), 'El historial conserva el equipo del torneo');
    rejected(fn() => $service->report('incidents', ['date_from'=>'2026-11-01','date_to'=>'2026-10-01']), 'Rango de fechas invertido');
    $newTeam = $service->save('teams', ['name'=>'Equipo de prueba','active'=>1]);
    rejected(fn() => $service->saveTournament(array_merge(['name'=>'Torneo inválido','start_date'=>'2026-11-02','end_date'=>'2026-11-01'], $tieRules)), 'Fechas del torneo en orden inválido');
    $invalidTieRules = $tieRules; $invalidTieRules['extra_time_periods'] = 0;
    rejected(fn() => $service->saveTournament(array_merge(['name'=>'Torneo reglas inválidas','start_date'=>'2026-11-01','end_date'=>'2026-11-30'], $invalidTieRules)), 'Períodos de prórroga deben ser positivos');
    $manualTournament = $service->saveTournament(array_merge(['name'=>'Torneo manual','start_date'=>'2026-11-01','end_date'=>'2026-11-30'], $tieRules));
    check((int)$manualTournament['extra_time_periods'] === 2 && (int)$manualTournament['extra_time_period_minutes'] === 10 && (int)$manualTournament['penalty_attempts_per_team'] === 5 && (int)$manualTournament['penalty_sudden_death'] === 1, 'Configuración de desempate persistida por torneo');
    $service->openRegistration((int)$manualTournament['id']);
    $service->enrollTeam((int)$manualTournament['id'], 1);
    $service->enrollTeam((int)$manualTournament['id'], (int)$newTeam['id']);
    rejected(fn() => $service->enrollTeam((int)$manualTournament['id'], 1), 'Inscripción duplicada en un torneo');
    $ordered = $service->orderParticipants((int)$manualTournament['id'], [(int)$newTeam['id'], 1]);
    check((int)$ordered['participants'][0]['team_id'] === (int)$newTeam['id'], 'Orden manual de equipos inscritos');
    $closed = $service->closeRegistration((int)$manualTournament['id'], 'manual');
    check($closed['draw_method'] === 'manual' && $closed['registrations_closed_at'] !== null, 'Cierre de inscripciones con orden manual');
    rejected(fn() => $service->removeParticipant((int)$manualTournament['id'], (int)$newTeam['id']), 'Cambios de participantes bloqueados tras el cierre');
    $randomTieRules = $tieRules; $randomTieRules['penalty_sudden_death'] = 0;
    $randomTournament = $service->saveTournament(array_merge(['name'=>'Torneo aleatorio','start_date'=>'2026-12-01','end_date'=>'2026-12-20'], $randomTieRules));
    $service->openRegistration((int)$randomTournament['id']);
    $service->enrollTeam((int)$randomTournament['id'], 1);
    $service->enrollTeam((int)$randomTournament['id'], 2);
    $closed = $service->closeRegistration((int)$randomTournament['id'], 'random');
    check($closed['draw_method'] === 'random', 'Cierre de inscripciones con sorteo aleatorio');
    rejected(fn() => $service->closeRegistration((int)$randomTournament['id'], 'invalid'), 'Método de sorteo no admitido');
    $randomBracket = $service->generateBracket((int)$randomTournament['id']);
    $randomMatch = $randomBracket['matches'][0];
    $service->scheduleMatch((int)$randomTournament['id'], (int)$randomMatch['id'], '2026-12-05T14:00', ['venue'=>'Cancha 1','referee_user_id'=>2], 1);
    rejected(fn() => $service->startMatch((int)$randomTournament['id'], (int)$randomMatch['id'], 999), 'Usuario no autorizado no puede iniciar el partido');
    $service->startMatch((int)$randomTournament['id'], (int)$randomMatch['id'], 2);
    rejected(fn() => $service->startMatch((int)$randomTournament['id'], (int)$randomMatch['id'], 2), 'No se puede iniciar dos veces el mismo partido');
    $incidentPlayer = current(array_filter($state['players'], fn($player) => in_array((int)$player['team_id'], [(int)$randomMatch['home_team_id'], (int)$randomMatch['away_team_id']], true)));
    $matchIncident = $service->saveMatchIncident((int)$randomTournament['id'], (int)$randomMatch['id'], 2, ['player_id'=>(int)$incidentPlayer['id'],'minute'=>12,'card_type'=>'yellow','description'=>'Falta táctica']);
    check((int)$matchIncident['team_id'] === (int)$incidentPlayer['team_id'] && (int)$matchIncident['minute'] === 12, 'Incidencia del árbitro asignado enlazada a equipo y minuto');
    rejected(fn() => $service->saveMatchIncident((int)$randomTournament['id'], (int)$randomMatch['id'], 1, ['player_id'=>(int)$incidentPlayer['id'],'minute'=>13,'card_type'=>'yellow','description'=>'Prueba']), 'Administrador no altera incidencias durante el juego');
    rejected(fn() => $service->save('incidents', ['player_id'=>(int)$incidentPlayer['id'],'description'=>'Cambio externo','card_type'=>'yellow','incident_date'=>$matchIncident['incident_date']], (int)$matchIncident['id']), 'Edición genérica no evita el permiso de incidencia del partido');
    rejected(fn() => $service->delete('incidents', (int)$matchIncident['id']), 'Eliminación genérica no evita el permiso de incidencia del partido');
    $matchIncident = $service->saveMatchIncident((int)$randomTournament['id'], (int)$randomMatch['id'], 2, ['player_id'=>(int)$incidentPlayer['id'],'minute'=>15,'card_type'=>'yellow','description'=>'Falta revisada'], (int)$matchIncident['id']);
    check($matchIncident['description'] === 'Falta revisada', 'Edición de incidencia durante el partido');
    $service->deleteMatchIncident((int)$randomTournament['id'], (int)$randomMatch['id'], 2, (int)$matchIncident['id']);
    check(!array_filter($service->state()['incidents'], fn($incident) => (int)($incident['tournament_match_id'] ?? 0) === (int)$randomMatch['id']), 'Eliminación de incidencia antes de finalizar el partido');
    $tiedPeriods = [
        ['period_number'=>0,'home_score'=>0,'away_score'=>0],
        ['period_number'=>1,'home_score'=>0,'away_score'=>0],
        ['period_number'=>2,'home_score'=>0,'away_score'=>0],
    ];
    $tiedKicks = [];
    $kickPattern = [1, 1, 1, 0, 0];
    for ($i=0; $i<5; $i++) {
        $tiedKicks[] = ['team_id'=>(int)$randomMatch['home_team_id'],'scored'=>$kickPattern[$i]];
        $tiedKicks[] = ['team_id'=>(int)$randomMatch['away_team_id'],'scored'=>$kickPattern[$i]];
    }
    rejected(fn() => $service->recordMatchResult((int)$randomTournament['id'], (int)$randomMatch['id'], ['actor_user_id'=>2,'period_scores'=>$tiedPeriods,'player_goals'=>[],'first_kicker_team_id'=>(int)$randomMatch['home_team_id'],'penalty_kicks'=>$tiedKicks]), 'Tanda empatada sin muerte súbita configurada');
    $bracketTournament = $service->saveTournament(array_merge(['name'=>'Cuadro de prueba','start_date'=>'2027-01-01','end_date'=>'2027-01-31'], $tieRules));
    $service->openRegistration((int)$bracketTournament['id']);
    foreach ([1, 2, 3, 4, (int)$newTeam['id']] as $teamId) $service->enrollTeam((int)$bracketTournament['id'], $teamId);
    rejected(fn() => $service->generateBracket((int)$bracketTournament['id']), 'Cuadro requiere inscripciones cerradas');
    $service->closeRegistration((int)$bracketTournament['id'], 'manual');
    $bracket = $service->generateBracket((int)$bracketTournament['id']);
    $byes = array_values(array_filter($bracket['matches'], fn($match) => $match['status'] === 'bye'));
    $roundTwo = array_values(array_filter($bracket['matches'], fn($match) => (int)$match['round_number'] === 2));
    check(count($bracket['matches']) === 7 && count($byes) === 3 && count($roundTwo) === 2, 'Cuadro de cinco equipos con tres byes explícitos');
    check(array_map('intval', array_column($byes, 'winner_team_id')) === [1, 2, 3] && count(array_filter($roundTwo, fn($match) => $match['home_team_id'] && $match['away_team_id'])) === 1, 'Byes asignados a las primeras posiciones manuales');
    $again = $service->generateBracket((int)$bracketTournament['id']);
    check(count($again['matches']) === count($bracket['matches']) && array_column($again['matches'], 'id') === array_column($bracket['matches'], 'id'), 'Generación idempotente del cuadro');
    $firstMatch = current(array_filter($bracket['matches'], fn($match) => (int)$match['round_number'] === 1 && (int)$match['match_number'] === 2));
    $historicalSchedule = $service->scheduleMatch((int)$bracketTournament['id'], (int)$firstMatch['id'], '2027-02-01T14:00', ['venue'=>'Cancha 1','referee_user_id'=>2], 1);
    check($historicalSchedule['workflow_status'] === 'scheduled', 'Se permiten programaciones históricas fuera de las fechas del torneo');
    $service->startMatch((int)$bracketTournament['id'], (int)$firstMatch['id'], 2);
    $homeScorer = current(array_filter($state['players'], fn($player) => (int)$player['team_id'] === (int)$firstMatch['home_team_id']));
    $alternateHomeScorer = current(array_filter($state['players'], fn($player) => (int)$player['team_id'] === (int)$firstMatch['home_team_id'] && (int)$player['id'] !== (int)$homeScorer['id']));
    rejected(fn() => $service->recordMatchGoal((int)$bracketTournament['id'], (int)$firstMatch['id'], 2, ['player_id'=>(int)$homeScorer['id'],'team_id'=>(int)$firstMatch['away_team_id'],'minute'=>10]), 'Gol rechazado si el jugador no pertenece al equipo seleccionado');
    $savedMatchGoal = $service->recordMatchGoal((int)$bracketTournament['id'], (int)$firstMatch['id'], 2, ['player_id'=>(int)$homeScorer['id'],'team_id'=>(int)$firstMatch['home_team_id'],'minute'=>10]);
    check((int)$savedMatchGoal['player_id'] === (int)$homeScorer['id'] && (int)$savedMatchGoal['team_id'] === (int)$firstMatch['home_team_id'] && (int)$savedMatchGoal['minute'] === 10 && count(array_filter($service->state()['tournament_match_goal_events'], fn($event) => (int)$event['tournament_match_id'] === (int)$firstMatch['id'])) === 1, 'Gol individualizado persiste durante el partido');
    $matchSuspension = $service->save('incidents', ['player_id'=>(int)$homeScorer['id'],'description'=>'Suspensión de prueba','card_type'=>'red','incident_date'=>'2026-12-01','suspension_date'=>'2027-01-10']);
    $goalsBeforeMatch = (int)$service->player((int)$alternateHomeScorer['id'])['total_goals'];
    rejected(fn() => $service->recordMatchResult((int)$bracketTournament['id'], (int)$firstMatch['id'], ['actor_user_id'=>2,
        'period_scores'=>[
            ['period_number'=>0,'home_score'=>1,'away_score'=>1,'home_own_goals'=>1],
            ['period_number'=>1,'home_score'=>1,'away_score'=>0],
            ['period_number'=>2,'home_score'=>0,'away_score'=>0],
        ],
        'player_goals'=>[['player_id'=>(int)$homeScorer['id'],'goals'=>1],['player_id'=>(int)$homeScorer['id'],'goals'=>1]],
    ]), 'Goleador duplicado en resultado de partido');
    rejected(fn() => $service->recordMatchResult((int)$bracketTournament['id'], (int)$firstMatch['id'], ['actor_user_id'=>2,
        'period_scores'=>[
            ['period_number'=>0,'home_score'=>1,'away_score'=>1,'home_own_goals'=>1],
            ['period_number'=>1,'home_score'=>1,'away_score'=>0],
            ['period_number'=>2,'home_score'=>0,'away_score'=>0],
        ],
        'player_goals'=>[['player_id'=>(int)$homeScorer['id'],'goals'=>2]],
    ]), 'Goleador suspendido en fecha del partido');
    $extraTimeInput = ['actor_user_id'=>2,
        'period_scores'=>[
            ['period_number'=>0,'home_score'=>1,'away_score'=>1,'home_own_goals'=>1],
            ['period_number'=>1,'home_score'=>1,'away_score'=>0],
            ['period_number'=>2,'home_score'=>0,'away_score'=>0],
        ],
        'player_goals'=>[['player_id'=>(int)$alternateHomeScorer['id'],'goals'=>2]],
        'goal_events'=>[
            ['player_id'=>(int)$alternateHomeScorer['id'],'minute'=>20],
            ['player_id'=>(int)$alternateHomeScorer['id'],'minute'=>50],
            ['player_id'=>(int)$homeScorer['id'],'minute'=>40,'own_goal'=>1],
        ],
    ];
    $redCard = $service->saveMatchIncident((int)$bracketTournament['id'], (int)$firstMatch['id'], 2, ['player_id'=>(int)$alternateHomeScorer['id'],'minute'=>30,'card_type'=>'red','description'=>'Expulsión de prueba','suspension_date'=>'2027-02-01']);
    rejected(fn() => $service->recordMatchResult((int)$bracketTournament['id'], (int)$firstMatch['id'], $extraTimeInput), 'Gol posterior a una expulsión del mismo partido');
    $service->saveMatchIncident((int)$bracketTournament['id'], (int)$firstMatch['id'], 2, ['player_id'=>(int)$alternateHomeScorer['id'],'minute'=>60,'card_type'=>'red','description'=>'Expulsión revisada','suspension_date'=>'2027-02-01'], (int)$redCard['id']);
    $extraTimeResult = $service->recordMatchResult((int)$bracketTournament['id'], (int)$firstMatch['id'], $extraTimeInput);
    $roundTwoOne = current(array_filter($extraTimeResult['matches'], fn($match) => (int)$match['round_number'] === 2 && (int)$match['match_number'] === 1));
    $pendingMatch = current(array_filter($extraTimeResult['matches'], fn($match) => (int)$match['id'] === (int)$firstMatch['id']));
    check($pendingMatch['workflow_status'] === 'awaiting_confirmation' && $roundTwoOne['away_team_id'] === null, 'El ganador no avanza antes de confirmar el resultado');
    $extraTimeResult = $service->recordMatchResult((int)$bracketTournament['id'], (int)$firstMatch['id'], $extraTimeInput, false, true);
    $matchAudit = App\Models\TournamentMatchResultAudit::find(['conditions' => 'tournament_match_id = :match:', 'bind' => ['match' => (int)$firstMatch['id']]]);
    check(in_array('result_revised', array_column($matchAudit->toArray(), 'action'), true), 'Edición antes de confirmar queda auditada');
    $extraTimeResult = $service->confirmMatchResult((int)$bracketTournament['id'], (int)$firstMatch['id'], 1);
    $roundTwoOne = current(array_filter($extraTimeResult['matches'], fn($match) => (int)$match['round_number'] === 2 && (int)$match['match_number'] === 1));
    check((int)$roundTwoOne['away_team_id'] === (int)$firstMatch['home_team_id'] && (int)$roundTwoOne['home_team_id'] === 1, 'Resultado de prórroga avanza al siguiente partido');
    check((int)$service->player((int)$alternateHomeScorer['id'])['total_goals'] === $goalsBeforeMatch + 2, 'Goles de juego suman al ranking; autogoles no se atribuyen');
    $storedEvents = array_values(array_filter($service->state()['tournament_match_goal_events'], fn($event) => (int)$event['tournament_match_id'] === (int)$firstMatch['id']));
    check(count($storedEvents) === 3 && array_sum(array_column($storedEvents, 'minute')) === 110, 'Goles y autogoles persisten con jugador, equipo y minuto');
    $penaltyMatch = $roundTwoOne;
    rejected(fn() => $service->scheduleMatch((int)$bracketTournament['id'], (int)$penaltyMatch['id'], '2027-02-01T14:00', ['venue'=>'Cancha 1','referee_user_id'=>2], 1), 'Equipo no puede programar partidos simultáneos');
    $service->scheduleMatch((int)$bracketTournament['id'], (int)$penaltyMatch['id'], '2027-01-15T14:00', ['venue'=>'Cancha 1','referee_user_id'=>2], 1);
    $service->startMatch((int)$bracketTournament['id'], (int)$penaltyMatch['id'], 2);
    $penaltyKicks = [];
    for ($i=0; $i<5; $i++) {
        $penaltyKicks[] = ['team_id'=>1,'scored'=>$kickPattern[$i]];
        $penaltyKicks[] = ['team_id'=>(int)$firstMatch['home_team_id'],'scored'=>$kickPattern[$i]];
    }
    $penaltyKicks[] = ['team_id'=>1,'scored'=>1];
    $penaltyKicks[] = ['team_id'=>(int)$firstMatch['home_team_id'],'scored'=>0];
    $afterPenalties = $service->recordMatchResult((int)$bracketTournament['id'], (int)$penaltyMatch['id'], ['actor_user_id'=>2,
        'period_scores'=>$tiedPeriods,
        'player_goals'=>[],
        'first_kicker_team_id'=>1,
        'penalty_kicks'=>$penaltyKicks,
    ]);
    $afterPenalties = $service->confirmMatchResult((int)$bracketTournament['id'], (int)$penaltyMatch['id'], 2);
    $penaltyResult = current(array_filter($afterPenalties['matches'], fn($match) => (int)$match['id'] === (int)$penaltyMatch['id']));
    check((int)$penaltyResult['winner_team_id'] === 1 && count(array_filter($service->state()['tournament_match_penalties'], fn($kick) => (int)$kick['tournament_match_id'] === (int)$penaltyMatch['id'])) === 12, 'Muerte súbita termina solo cuando hay ganador');
    rejected(fn() => $service->recordMatchResult((int)$bracketTournament['id'], (int)$penaltyMatch['id'], ['actor_user_id'=>2], true), 'Árbitro no puede corregir un resultado confirmado');
    $correction = $service->recordMatchResult((int)$bracketTournament['id'], (int)$penaltyMatch['id'], [
        'actor_user_id'=>1,
        'period_scores'=>$tiedPeriods,
        'player_goals'=>[],
        'goal_events'=>[],
        'first_kicker_team_id'=>1,
        'penalty_kicks'=>$penaltyKicks,
    ], true);
    $finalMatch = current(array_filter($correction['matches'], fn($match) => (int)$match['round_number'] === 3));
    check($finalMatch['workflow_status'] === 'unprogrammed' && $finalMatch['home_team_id'] === null, 'Corrección retira temporalmente el clasificado previo');
    $correction = $service->confirmMatchResult((int)$bracketTournament['id'], (int)$penaltyMatch['id'], 1);
    $finalMatch = current(array_filter($correction['matches'], fn($match) => (int)$match['round_number'] === 3));
    $auditActions = array_column(App\Models\TournamentMatchResultAudit::find(['conditions' => 'tournament_match_id = :match:', 'bind' => ['match' => (int)$penaltyMatch['id']]])->toArray(), 'action');
    check((int)$finalMatch['home_team_id'] === (int)$penaltyResult['winner_team_id'] && in_array('exceptional_correction', $auditActions, true), 'Corrección administrativa auditada y avance confirmado sin duplicados');
    $newPlayer = $service->save('players', ['first_name'=>'Jean-Luc','last_name'=>"D'Ávila",'birth_date'=>'2015-04-02','team_id'=>$newTeam['id'],'shirt_number'=>10,'position'=>'Delantero']);
    check($newPlayer['photo_path'] === 'images/player-placeholder.svg', 'Nuevo jugador y fotografía de respaldo');
    $photoPath = 'uploads/players/' . str_repeat('a', 32) . '.jpg';
    $newPlayer = $service->save('players', ['first_name'=>'Jean-Luc','last_name'=>"D'Ávila",'birth_date'=>'2015-04-02','team_id'=>$newTeam['id'],'shirt_number'=>10,'position'=>'Delantero','photo_path'=>$photoPath], (int)$newPlayer['id'], true);
    $newPlayer = $service->save('players', $newPlayer, (int)$newPlayer['id'], false, true);
    check($newPlayer['photo_path'] === 'images/player-placeholder.svg', 'Eliminación de fotografía sin borrar al jugador');
    $newDay = $service->save('matchdays', ['number'=>6,'game_date'=>'2026-11-07']);
    $goal = $service->save('goals', ['player_id'=>$newPlayer['id'],'matchday_id'=>$newDay['id'],'goals'=>0]);
    check((int)$goal['goals'] === 0, 'Cero goles es un valor válido');
    $goal = $service->save('goals', ['player_id'=>$newPlayer['id'],'matchday_id'=>$newDay['id'],'goals'=>4], (int)$goal['id']);
    check($service->player((int)$newPlayer['id'])['total_goals'] === 4, 'Edición de goles actualiza los reportes');
    $yellow = $service->save('incidents', ['player_id'=>$newPlayer['id'],'description'=>'Prueba de amarilla','card_type'=>'yellow','incident_date'=>'2026-11-07','suspension_date'=>'2026-11-08']);
    check($yellow['suspension_date'] === null, 'Amarilla no genera suspensión');
    $service->save('teams', ['name'=>'Equipo actualizado','active'=>0], (int)$newTeam['id']);
    rejected(fn()=>$service->save('players',['first_name'=>'Nuevo','last_name'=>'Jugador','birth_date'=>'2015-04-02','team_id'=>$newTeam['id']]), 'Equipo inactivo no recibe nuevos jugadores');
    $service->delete('goals', (int)$goal['id']); $service->delete('incidents', (int)$yellow['id']); $service->delete('incidents', (int)$matchSuspension['id']);
    $service->delete('players', (int)$newPlayer['id']);
    $testTournamentIds = [(int)$manualTournament['id'], (int)$randomTournament['id'], (int)$bracketTournament['id']];
    $di->getShared('db')->execute('DELETE FROM tournament_match_penalties WHERE tournament_match_id IN (SELECT id FROM tournament_matches WHERE tournament_id IN (?, ?, ?))', $testTournamentIds);
    $di->getShared('db')->execute('DELETE FROM tournament_match_goals WHERE tournament_match_id IN (SELECT id FROM tournament_matches WHERE tournament_id IN (?, ?, ?))', $testTournamentIds);
    $di->getShared('db')->execute('DELETE FROM tournament_match_goal_events WHERE tournament_match_id IN (SELECT id FROM tournament_matches WHERE tournament_id IN (?, ?, ?))', $testTournamentIds);
    $di->getShared('db')->execute('DELETE FROM tournament_match_period_scores WHERE tournament_match_id IN (SELECT id FROM tournament_matches WHERE tournament_id IN (?, ?, ?))', $testTournamentIds);
    $di->getShared('db')->execute('DELETE FROM tournament_match_result_audit WHERE tournament_match_id IN (SELECT id FROM tournament_matches WHERE tournament_id IN (?, ?, ?))', $testTournamentIds);
    $di->getShared('db')->execute('DELETE FROM incidents WHERE tournament_match_id IN (SELECT id FROM tournament_matches WHERE tournament_id IN (?, ?, ?))', $testTournamentIds);
    $di->getShared('db')->execute('DELETE FROM tournament_matches WHERE tournament_id IN (?, ?, ?)', $testTournamentIds);
    $di->getShared('db')->execute('DELETE FROM tournament_teams WHERE tournament_id IN (?, ?, ?)', $testTournamentIds);
    $di->getShared('db')->execute('DELETE FROM tournaments WHERE id IN (?, ?, ?)', $testTournamentIds);
    $service->delete('teams', (int)$newTeam['id']); $service->delete('matchdays', (int)$newDay['id']);
    check(count($service->state()['players']) === 20 && count($service->state()['matchdays']) === 5, 'CRUD completo y eliminación de registros sin asociaciones');
    $acl = $di->getShared('acl');
    check($acl->isAllowed('admin','tournament','write') && $acl->isAllowed('referee','tournament','read') && !$acl->isAllowed('referee','tournament','write'), 'ACL de administrador y árbitro');
    echo "\n{$checks} verificaciones correctas.\n";
} finally {
    if (isset($photoDirectory) && is_dir($photoDirectory)) {
        chmod($photoDirectory, 0755);
        if (isset($photoProbe) && is_file($photoProbe)) unlink($photoProbe);
        rmdir($photoDirectory);
    }
    if (is_file($file)) unlink($file);
}
