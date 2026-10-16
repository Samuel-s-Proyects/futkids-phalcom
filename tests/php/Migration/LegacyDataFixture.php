<?php
declare(strict_types=1);
namespace Tests\Migration;

use Phalcon\Db\Adapter\AdapterInterface;

/**
 * Data as the previous version (migration 007) stored it: two teams, global matchdays with goals,
 * a red-card suspension, and two accounts. Inserted with plain SQL because the models describe the
 * current schema.
 */
final class LegacyDataFixture
{
    public static function load(AdapterInterface $db): void {
        foreach ([[1, 'Leones de Petapa'], [2, 'Halcones Azules'], [3, 'Equipo sin historial']] as [$id, $name]) {
            $db->insertAsDict('teams', ['id' => $id, 'name' => $name, 'active' => 1]);
        }
        foreach ([[1, 'Mateo', 1], [2, 'Santiago', 1], [6, 'Lucas', 2]] as [$id, $name, $team]) {
            $db->insertAsDict('players', ['id' => $id, 'first_name' => $name, 'last_name' => 'Prueba', 'birth_date' => '2015-01-01', 'team_id' => $team]);
        }
        foreach ([[1, 'Administrador', 'admin@cantera.local', 'admin'], [2, 'Árbitro', 'arbitro@cantera.local', 'referee']] as [$id, $name, $email, $role]) {
            $db->insertAsDict('users', ['id' => $id, 'name' => $name, 'email' => $email, 'role' => $role, 'password_hash' => 'x']);
        }
        foreach ([[7, 1, '2026-09-05'], [8, 2, '2026-09-12']] as [$id, $number, $date]) {
            $db->insertAsDict('matchdays', ['id' => $id, 'number' => $number, 'game_date' => $date]);
        }
        $db->insertAsDict('goals', ['player_id' => 1, 'matchday_id' => 7, 'goals' => 3]);
        $db->insertAsDict('goals', ['player_id' => 6, 'matchday_id' => 8, 'goals' => 1]);
        $db->insertAsDict('incidents', ['player_id' => 2, 'description' => 'Expulsión', 'card_type' => 'red', 'incident_date' => '2026-09-05', 'suspension_date' => '2026-09-12']);
    }
}
