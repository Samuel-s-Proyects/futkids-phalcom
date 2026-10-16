<?php
declare(strict_types=1);
namespace App\Services\Match;

use App\Exceptions\HttpException;
use App\Models\TournamentMatch;
use App\Models\User;

/** Who may operate a match: an administrator, or the referee assigned to it. */
final class MatchAccess
{
    public function operator(TournamentMatch $match, int $userId): User {
        $user = User::findFirst($userId);
        if (!$user || !$user->isActive() || !in_array((string)$user->role, ['admin', 'referee'], true)) {
            throw new HttpException('Usuario sin permisos para operar este partido.', 403);
        }
        if ($user->role === 'referee' && (int)$match->referee_user_id !== $userId) {
            throw new HttpException('Este partido no está asignado a su cuenta de árbitro.', 403);
        }
        return $user;
    }

    public function admin(TournamentMatch $match, int $userId, string $message): User {
        $user = $this->operator($match, $userId);
        if ($user->role !== 'admin') throw new HttpException($message, 403);
        return $user;
    }

    /** Disciplinary incidents are written only by the assigned referee while the match is being played. */
    public function activeReferee(TournamentMatch $match, int $userId): User {
        $user = $this->operator($match, $userId);
        if ($user->role !== 'referee') throw new HttpException('Solo el árbitro asignado puede registrar incidencias durante el partido.', 403);
        if ($match->workflow_status !== TournamentMatch::IN_PROGRESS) throw new HttpException('Las incidencias solo se pueden modificar mientras el partido está en juego.', 409);
        return $user;
    }
}
