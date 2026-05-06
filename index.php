<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use McpServer\Web\Router;
use McpServer\Web\SsoController;

$router     = new Router();
$controller = new SsoController();

// SSO Web UI
$router->get('/',           fn() => $controller->dashboard());
$router->get('/login',      fn() => $controller->loginForm());
$router->post('/login',     fn() => $controller->loginPost());
$router->get('/logout',     fn() => $controller->logout());
$router->get('/authorize',  fn() => $controller->authorizeGet());
$router->post('/authorize', fn() => $controller->authorizePost());
$router->get('/clients',    fn() => $controller->clients());
$router->post('/clients',   fn() => $controller->clientsPost());
$router->get('/users',      fn() => $controller->users());
$router->post('/users',     fn() => $controller->usersPost());
$router->get('/docs',       fn() => $controller->docs());

// OAuth2 API endpoints (no auth required — they validate credentials internally)
$router->post('/token',     fn() => $controller->token());
$router->post('/revoke',    fn() => $controller->revoke());

$router->dispatch();
