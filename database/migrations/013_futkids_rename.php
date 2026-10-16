<?php
declare(strict_types=1);

use App\Support\Clock;
use Phalcon\Db\Adapter\AdapterInterface;
use Phalcon\Db\Enum;

/**
 * The project was renamed from Cantera to FutKids: accounts on the old demo domain move to @futkids.local.
 * An address is left as is when its new form is already taken. Passwords are hashed and cannot be renamed;
 * change them with scripts/change_password.php or from Usuarios. Safe to run more than once.
 */
return static function (AdapterInterface $db, string $driver, Clock $clock): void {
    $users = $db->fetchAll("SELECT id, email FROM users WHERE email LIKE '%@cantera.local'", Enum::FETCH_ASSOC);
    foreach ($users as $user) {
        $email = substr((string)$user['email'], 0, -strlen('@cantera.local')) . '@futkids.local';
        $taken = $db->fetchOne('SELECT id FROM users WHERE email = ?', Enum::FETCH_ASSOC, [$email]);
        if ($taken) continue;
        $db->updateAsDict('users', ['email' => $email], ['conditions' => 'id = ?', 'bind' => [(int)$user['id']]]);
    }
};
