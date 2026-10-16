<?php
declare(strict_types=1);
namespace App\Services\Match;

use App\Exceptions\HttpException;
use App\Models\Incident;
use App\Models\Player;
use App\Models\TournamentMatch;
use App\Validation\Validator;

/** Cards shown during a match, recorded by the assigned referee or an administrator while it is being played. */
final class MatchIncidentService
{
    public function __construct(private MatchAccess $access, private MatchAudit $audit) {}

    public function save(TournamentMatch $match, int $actorId, array $input, int $incidentId = 0): array {
        $this->access->activeOperator($match, $actorId);
        $matchDate = $match->matchDate();
        $v = new Validator($input);
        $playerId = $v->integer('player_id', 1, PHP_INT_MAX);
        $minute = $v->integer('minute', 0, 150);
        $cardType = $v->choice('card_type', ['yellow', 'red'], 'Seleccione tarjeta amarilla o roja.');
        $description = $v->text('description', 500, 'description', multiline: true);
        $suspensionDate = $cardType === 'red' ? $v->date('suspension_date') : null;
        if ($suspensionDate !== null && $suspensionDate < $matchDate) {
            $v->fail('suspension_date', 'La suspensión debe ser igual o posterior a la fecha del partido.');
        }
        $player = $playerId ? Player::findFirst($playerId) : null;
        if ($playerId && (!$player || !$match->involves((int)$player->team_id))) {
            $v->fail('player_id', 'Seleccione un jugador de los equipos que juegan.');
        }
        $v->assertValid();

        $incident = $incidentId ? $this->incident($match, $incidentId) : new Incident();
        $before = $incidentId ? $incident->toArray() : null;
        $incident->assign([
            'player_id' => $playerId,
            'team_id' => (int)$player->team_id,
            'tournament_match_id' => (int)$match->id,
            'minute' => $minute,
            'card_type' => $cardType,
            'description' => $description,
            'incident_date' => $matchDate,
            'suspension_date' => $suspensionDate,
        ]);
        $incident->saveOrFail('No se pudo guardar la incidencia.');
        $this->audit->record($match, $actorId, $incidentId ? 'incident_updated' : 'incident_created', ['before' => $before, 'after' => $incident->toArray()]);
        return $incident->toArray();
    }

    public function delete(TournamentMatch $match, int $actorId, int $incidentId): void {
        $this->access->activeOperator($match, $actorId);
        $incident = $this->incident($match, $incidentId);
        $snapshot = $incident->toArray();
        $incident->deleteOrFail('No se pudo eliminar la incidencia.');
        $this->audit->record($match, $actorId, 'incident_deleted', $snapshot);
    }

    private function incident(TournamentMatch $match, int $incidentId): Incident {
        $incident = Incident::findFirst(['conditions' => 'id = :id: AND tournament_match_id = :match:', 'bind' => ['id' => $incidentId, 'match' => (int)$match->id]]);
        if (!$incident) throw new HttpException('La incidencia no existe en este partido.', 404);
        return $incident;
    }
}
