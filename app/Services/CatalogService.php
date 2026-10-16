<?php
declare(strict_types=1);
namespace App\Services;

use App\Exceptions\HttpException;
use App\Models\Goal;
use App\Models\Incident;
use App\Models\Matchday;
use App\Models\Model;
use App\Models\Player;
use App\Models\Team;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchGoal;
use App\Models\TournamentTeam;
use App\Support\Clock;
use App\Support\Dates;
use App\Support\TextNormalizer;
use App\Validation\Validator;

/** Teams, players and disciplinary incidents outside a match. Historic matchdays and goals are read-only. */
final class CatalogService
{
    public const POSITIONS = ['Portero', 'Defensa', 'Mediocampista', 'Delantero'];
    private const MODELS = ['teams' => Team::class, 'players' => Player::class, 'incidents' => Incident::class];
    private const PLACEHOLDER_PHOTO = 'images/player-placeholder.svg';

    public function __construct(private Clock $clock, private array $settings) {}

    /**
     * @param ?string $photoPath a freshly stored photo for players (null keeps the current one)
     * @return array the saved record
     */
    public function save(string $entity, array $input, int $id = 0, ?string $photoPath = null, bool $removePhoto = false): array {
        $row = $id ? $this->record($entity, $id) : new (self::model($entity))();
        if ($entity === 'incidents' && $row->tournament_match_id) {
            throw new HttpException('Las incidencias de partido solo se modifican desde el partido y con el árbitro asignado.', 403);
        }
        $data = match ($entity) {
            'teams' => $this->team($input, $id),
            'players' => $this->player($input, $row, $id),
            'incidents' => $this->incident($input),
        };
        $oldPhoto = $entity === 'players' ? $row->photo_path : null;
        if ($entity === 'players') {
            $data['photo_path'] = $photoPath ?? ($removePhoto || !$id ? self::PLACEHOLDER_PHOTO : $row->photo_path);
        }
        $row->assign($data);
        $row->saveOrFail('No se pudo guardar.');
        if ($entity === 'players' && $oldPhoto !== $row->photo_path) $this->removePhotoIfUnused($oldPhoto);
        return $row->toArray();
    }

    public function delete(string $entity, int $id): void {
        $row = $this->record($entity, $id);
        if ($entity === 'incidents' && $row->tournament_match_id) {
            throw new HttpException('Las incidencias de partido solo se eliminan desde el partido y con el árbitro asignado.', 403);
        }
        $references = match ($entity) {
            'teams' => Player::count(['team_id = :i:', 'bind' => ['i' => $id]])
                + TournamentTeam::count(['team_id = :i:', 'bind' => ['i' => $id]])
                + TournamentMatch::count(['home_team_id = :i: OR away_team_id = :i:', 'bind' => ['i' => $id]]),
            'players' => Goal::count(['player_id = :i:', 'bind' => ['i' => $id]])
                + TournamentMatchGoal::count(['player_id = :i:', 'bind' => ['i' => $id]])
                + Incident::count(['player_id = :i:', 'bind' => ['i' => $id]]),
            default => 0,
        };
        if ($references) throw new HttpException('Este registro tiene información asociada. Edite sus datos o retire primero las asociaciones.', 409);
        $row->deleteOrFail('No se pudo eliminar el registro.');
        if ($entity === 'players') $this->removePhotoIfUnused($row->photo_path);
    }

    public static function model(string $entity): string {
        return self::MODELS[$entity] ?? throw new HttpException('Catálogo no encontrado.', 404);
    }

    private function record(string $entity, int $id): Model {
        return (self::model($entity))::findOrFail($id);
    }

    private function team(array $input, int $id): array {
        $v = new Validator($input);
        $name = $v->text('name', 100, 'title');
        $active = $v->has('active') ? $v->flag('active', 'Indique si el equipo está activo.') : 1;
        if ($name && $this->taken(Team::class, 'name', $name, $id)) $v->fail('name', 'Ya existe un equipo con ese nombre.');
        $v->assertValid();
        return ['name' => $name, 'active' => $active];
    }

    private function player(array $input, Model $current, int $id): array {
        $v = new Validator($input);
        $firstName = $v->text('first_name', 100, 'person_name');
        $lastName = $v->text('last_name', 100, 'person_name');
        $birthDate = $v->date('birth_date');
        $teamId = $v->integer('team_id', 1, PHP_INT_MAX);
        $shirt = $v->integer('shirt_number', 1, 99, required: false);
        $position = $v->choice('position', self::POSITIONS, 'Elija una posición de la lista.', required: false);

        if ($birthDate) {
            $reference = $this->settings['age_reference_date'];
            $age = (new \DateTimeImmutable($birthDate))->diff(new \DateTimeImmutable($reference))->y;
            if ($birthDate > $this->clock->today() || $birthDate > $reference || $age < $this->settings['age_min'] || $age > $this->settings['age_max']) {
                $v->fail('birth_date', "Edad permitida: de {$this->settings['age_min']} a {$this->settings['age_max']} años al " . Dates::display($reference) . '.');
            }
        }
        $team = $teamId ? Team::findFirst($teamId) : null;
        if ($teamId && !$team) $v->fail('team_id', 'Seleccione un equipo de la lista.');
        $changesTeam = $id && (int)$current->team_id !== $teamId;
        if ($team && !(int)$team->active && (!$id || $changesTeam)) $v->fail('team_id', 'El equipo está inactivo; elija un equipo activo.');
        if ($changesTeam && $this->hasHistory($id)) $v->fail('team_id', 'Un jugador con goles o incidencias debe conservar su equipo durante el torneo.');
        if ($shirt && $teamId && Player::count(['team_id = :t: AND shirt_number = :n: AND id != :id:', 'bind' => ['t' => $teamId, 'n' => $shirt, 'id' => $id]])) {
            $v->fail('shirt_number', "El dorsal {$shirt} ya lo usa otro jugador del equipo.");
        }
        $v->assertValid();
        return ['first_name' => $firstName, 'last_name' => $lastName, 'birth_date' => $birthDate, 'team_id' => $teamId, 'shirt_number' => $shirt, 'position' => $position];
    }

    private function incident(array $input): array {
        $v = new Validator($input);
        $playerId = $v->integer('player_id', 1, PHP_INT_MAX);
        $description = $v->text('description', 500, 'description', multiline: true);
        $cardType = $v->choice('card_type', ['yellow', 'red'], 'Seleccione tarjeta amarilla o roja.');
        $incidentDate = $v->date('incident_date');
        $suspensionDate = $cardType === 'red' ? $v->date('suspension_date') : null;
        if ($incidentDate && $incidentDate > $this->clock->today()) $v->fail('incident_date', 'La incidencia no puede tener una fecha futura.');
        if ($incidentDate && $suspensionDate && $suspensionDate < $incidentDate) $v->fail('suspension_date', 'La suspensión no puede ser anterior a la incidencia.');
        if ($playerId && !Player::findFirst($playerId)) $v->fail('player_id', 'Seleccione un jugador de la lista.');
        $v->assertValid();
        if ($suspensionDate && $this->hasGoalsOn($playerId, $suspensionDate)) {
            throw new HttpException('Ya hay goles registrados en la fecha de suspensión. Corrija ese registro primero.', 409, ['suspension_date' => 'El jugador tiene goles en esa fecha.']);
        }
        return ['player_id' => $playerId, 'description' => $description, 'card_type' => $cardType, 'incident_date' => $incidentDate, 'suspension_date' => $suspensionDate];
    }

    private function hasHistory(int $playerId): bool {
        $bind = ['bind' => ['p' => $playerId]];
        return (bool)(Goal::count(['player_id = :p:'] + $bind) + TournamentMatchGoal::count(['player_id = :p:'] + $bind) + Incident::count(['player_id = :p:'] + $bind));
    }

    private function hasGoalsOn(int $playerId, string $date): bool {
        foreach (Goal::find(['player_id = :p:', 'bind' => ['p' => $playerId]]) as $goal) {
            if ((string)Matchday::findFirst((int)$goal->matchday_id)?->game_date === $date) return true;
        }
        return false;
    }

    /** Case, accent and punctuation-insensitive uniqueness. */
    private function taken(string $model, string $field, string $value, int $exceptId): bool {
        $key = TextNormalizer::key($value);
        foreach ($model::find(['columns' => "id, {$field}"]) as $row) {
            if ((int)$row->id !== $exceptId && TextNormalizer::key((string)$row->$field) === $key) return true;
        }
        return false;
    }

    private function removePhotoIfUnused(?string $path): void {
        if (!$path || !preg_match('~^uploads/players/[a-f0-9]{32}\.jpg$~', $path)) return;
        if (!Player::count(['photo_path = :path:', 'bind' => ['path' => $path]])) PhotoStorage::remove($path);
    }
}
