<?php
declare(strict_types=1);

use Phalcon\Di\DiInterface;

/**
 * Builds the dependency container for the given settings.
 * Kept separate from bootstrap.php so tests can create isolated containers (one SQLite file per test).
 */
return static function (array $settings): DiInterface {
    $di = new Phalcon\Di\FactoryDefault();
    $di->setShared('settings', fn() => $settings);
    $di->setShared('clock', fn() => $settings['environment'] === 'testing' && $settings['fixed_now'] !== ''
        ? App\Support\FrozenClock::at($settings['fixed_now'], $settings['timezone'])
        : new App\Support\SystemClock($settings['timezone']));
    $di->setShared('db', function () use ($settings) {
        $db = $settings['database'];
        if ($db['driver'] === 'sqlite') {
            $connection = new Phalcon\Db\Adapter\Pdo\Sqlite(['dbname' => $db['dbname']]);
            $connection->execute('PRAGMA foreign_keys = ON');
            return $connection;
        }
        unset($db['driver']);
        return new Phalcon\Db\Adapter\Pdo\Mysql($db);
    });
    $di->setShared('security', fn() => new Phalcon\Encryption\Security());
    $di->setShared('session', function () use ($settings) {
        if (PHP_SAPI !== 'cli') {
            ini_set('session.use_strict_mode', '1');
            session_name('cantera_session');
            session_set_cookie_params(['httponly' => true, 'secure' => ($settings['environment'] === 'production' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')), 'samesite' => 'Lax', 'path' => '/']);
        }
        $session = new Phalcon\Session\Manager();
        $session->setAdapter(new Phalcon\Session\Adapter\Stream(['savePath' => BASE_PATH . '/var/session']));
        $session->start();
        return $session;
    });
    $di->setShared('acl', function () {
        $acl = new Phalcon\Acl\Adapter\Memory();
        $acl->setDefaultAction(Phalcon\Acl\Enum::DENY);
        $acl->addRole('admin');
        $acl->addRole('referee');
        $acl->addComponent('tournament', ['read', 'write']);
        $acl->addComponent('users', ['read', 'write']);
        $acl->allow('admin', 'tournament', ['read', 'write']);
        $acl->allow('admin', 'users', ['read', 'write']);
        $acl->allow('referee', 'tournament', 'read');
        return $acl;
    });
    $di->setShared('dispatcher', function () {
        $dispatcher = new Phalcon\Mvc\Dispatcher();
        $dispatcher->setDefaultNamespace('App\\Controllers');
        return $dispatcher;
    });
    $di->setShared('view', function () use ($di) {
        $view = new Phalcon\Mvc\View();
        $view->setViewsDir(BASE_PATH . '/app/views/');
        $view->registerEngines(['.volt' => function ($view) use ($di) {
            $volt = new Phalcon\Mvc\View\Engine\Volt($view, $di);
            $volt->setOptions(['path' => BASE_PATH . '/var/cache/', 'always' => true]);
            return $volt;
        }]);
        return $view;
    });
    $di->setShared('router', fn() => require BASE_PATH . '/config/routes.php');
    $di->setShared('migrator', fn() => new App\Database\Migrator(
        $di->getShared('db'),
        $settings['database']['driver'],
        BASE_PATH . '/database/migrations',
        $di->getShared('clock'),
    ));
    $di->setShared('transaction', fn() => new App\Support\Transaction($di->getShared('db'), $settings['database']['driver']));
    $di->setShared('suspensions', fn() => new App\Services\SuspensionService());
    $di->setShared('seeding', fn() => new App\Services\TournamentSeeding($di->getShared('db')));
    $di->setShared('formats', fn() => new App\Services\Format\Formats([
        App\Models\Tournament::KNOCKOUT => new App\Services\Knockout\KnockoutEngine($di->getShared('clock'), $di->getShared('seeding')),
        App\Models\Tournament::LEAGUE => new App\Services\League\LeagueEngine($di->getShared('clock'), $di->getShared('seeding'), $di->getShared('matchAudit')),
    ]));
    $di->setShared('matchAudit', fn() => new App\Services\Match\MatchAudit());
    $di->setShared('matchAccess', fn() => new App\Services\Match\MatchAccess());
    $di->setShared('tournaments', fn() => new App\Services\TournamentService($di->getShared('clock'), $di->getShared('formats'), $di->getShared('seeding')));
    $di->setShared('scheduler', fn() => new App\Services\Match\MatchScheduler($di->getShared('clock'), $di->getShared('formats'), $di->getShared('matchAudit')));
    $di->setShared('matchSheet', fn() => new App\Services\Match\MatchSheetService(
        $di->getShared('clock'),
        $di->getShared('formats'),
        $di->getShared('matchAccess'),
        $di->getShared('matchAudit'),
        $di->getShared('suspensions'),
    ));
    $di->setShared('matchIncidents', fn() => new App\Services\Match\MatchIncidentService($di->getShared('matchAccess'), $di->getShared('matchAudit')));
    $di->setShared('catalog', fn() => new App\Services\CatalogService($di->getShared('clock'), $settings));
    $di->setShared('reports', fn() => new App\Services\ReportService($di->getShared('db'), $di->getShared('suspensions'), $settings));
    $di->setShared('users', fn() => new App\Services\UserService($di->getShared('security'), $di->getShared('clock')));
    $di->setShared('state', fn() => new App\Services\StateService($di->getShared('tournaments')));
    return $di;
};
