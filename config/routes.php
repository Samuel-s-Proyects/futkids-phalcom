<?php
$router = new Phalcon\Mvc\Router(false);
$router->removeExtraSlashes(true);

$router->addGet('/', ['controller' => 'index', 'action' => 'index']);
$router->addGet('/api/health', ['controller' => 'index', 'action' => 'health']);
$router->addGet('/api/session', ['controller' => 'auth', 'action' => 'session']);
$router->addPost('/api/login', ['controller' => 'auth', 'action' => 'login']);
$router->addPost('/api/logout', ['controller' => 'auth', 'action' => 'logout']);
$router->addGet('/api/state', ['controller' => 'records', 'action' => 'state']);

// Tournaments
$tournament = '/api/tournaments/{id:[0-9]+}';
$router->addGet('/api/tournaments', ['controller' => 'tournaments', 'action' => 'list']);
$router->addPost('/api/tournaments', ['controller' => 'tournaments', 'action' => 'save']);
$router->addGet($tournament, ['controller' => 'tournaments', 'action' => 'detail']);
$router->addPost($tournament, ['controller' => 'tournaments', 'action' => 'save']);
$router->addPost($tournament . '/registration', ['controller' => 'tournaments', 'action' => 'openRegistration']);
$router->addPost($tournament . '/registration/close', ['controller' => 'tournaments', 'action' => 'closeRegistration']);
$router->addPost($tournament . '/participants', ['controller' => 'tournaments', 'action' => 'enroll']);
$router->addPost($tournament . '/participants/order', ['controller' => 'tournaments', 'action' => 'orderParticipants']);
$router->addDelete($tournament . '/participants/{teamId:[0-9]+}', ['controller' => 'tournaments', 'action' => 'removeParticipant']);
$router->addPost($tournament . '/bracket', ['controller' => 'tournaments', 'action' => 'generateBracket']);
$router->addGet($tournament . '/scorers', ['controller' => 'tournaments', 'action' => 'scorers']);
$router->addPost($tournament . '/end-date', ['controller' => 'tournaments', 'action' => 'extendEndDate']);

// League calendar and table
$router->addPost($tournament . '/fixture/preview', ['controller' => 'tournaments', 'action' => 'fixturePreview']);
$router->addPost($tournament . '/fixture', ['controller' => 'tournaments', 'action' => 'generateFixture']);
$router->addGet($tournament . '/standings', ['controller' => 'tournaments', 'action' => 'standings']);
$router->addPost($tournament . '/matchdays', ['controller' => 'tournaments', 'action' => 'addMatchday']);
$router->addPost($tournament . '/matchdays/{matchdayId:[0-9]+}', ['controller' => 'tournaments', 'action' => 'updateMatchday']);
$router->addDelete($tournament . '/matchdays/{matchdayId:[0-9]+}', ['controller' => 'tournaments', 'action' => 'deleteMatchday']);

// Matches
$match = $tournament . '/matches/{matchId:[0-9]+}';
$router->addPost($match . '/schedule', ['controller' => 'matches', 'action' => 'schedule']);
$router->addPost($match . '/start', ['controller' => 'matches', 'action' => 'start']);
$router->addPost($match . '/goals', ['controller' => 'matches', 'action' => 'addGoal']);
$router->addPost($match . '/goals/{eventId:[0-9]+}', ['controller' => 'matches', 'action' => 'updateGoal']);
$router->addDelete($match . '/goals/{eventId:[0-9]+}', ['controller' => 'matches', 'action' => 'deleteGoal']);
$router->addPost($match . '/result', ['controller' => 'matches', 'action' => 'result']);
$router->addPost($match . '/revision', ['controller' => 'matches', 'action' => 'revision']);
$router->addPost($match . '/confirm', ['controller' => 'matches', 'action' => 'confirm']);
$router->addPost($match . '/correction', ['controller' => 'matches', 'action' => 'correction']);
$router->addPost($match . '/postpone', ['controller' => 'matches', 'action' => 'postpone']);
$router->addPost($match . '/matchday', ['controller' => 'matches', 'action' => 'move']);
$router->addPost($match . '/incidents', ['controller' => 'matches', 'action' => 'saveIncident']);
$router->addPost($match . '/incidents/{incidentId:[0-9]+}', ['controller' => 'matches', 'action' => 'saveIncident']);
$router->addDelete($match . '/incidents/{incidentId:[0-9]+}', ['controller' => 'matches', 'action' => 'deleteIncident']);

// Accounts
$router->addGet('/api/users', ['controller' => 'users', 'action' => 'list']);
$router->addPost('/api/users', ['controller' => 'users', 'action' => 'create']);
$router->addPost('/api/users/{id:[0-9]+}', ['controller' => 'users', 'action' => 'update']);
$router->addPost('/api/users/{id:[0-9]+}/status', ['controller' => 'users', 'action' => 'status']);
$router->addPost('/api/users/{id:[0-9]+}/password', ['controller' => 'users', 'action' => 'password']);
$router->addPost('/api/account/password', ['controller' => 'account', 'action' => 'password']);

// Catalogue
$entities = '(teams|players|matchdays|goals|incidents)';
$editable = '(teams|players|incidents)'; // matchdays and goals are historic (read-only)
$router->addGet('/api/{entity:' . $entities . '}', ['controller' => 'records', 'action' => 'list']);
$router->addPost('/api/{entity:' . $editable . '}', ['controller' => 'records', 'action' => 'save']);
$router->addPost('/api/{entity:' . $editable . '}/{id:[0-9]+}', ['controller' => 'records', 'action' => 'save']);
$router->addDelete('/api/{entity:' . $editable . '}/{id:[0-9]+}', ['controller' => 'records', 'action' => 'delete']);

// Reports
$router->addGet('/api/reports/{kind:(referee|incidents|scorers)}', ['controller' => 'reports', 'action' => 'report']);
$router->addGet('/api/player/{id:[0-9]+}', ['controller' => 'reports', 'action' => 'player']);

$router->notFound(['controller' => 'index', 'action' => 'notFound']);
return $router;
