<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use McpServer\Web\Router;
use McpServer\Web\AuthController;
use McpServer\Web\DashboardController;
use McpServer\Web\OAuthController;
use McpServer\Web\ClientsController;
use McpServer\Web\UsersController;
use McpServer\Web\DocsController;

$router    = new Router();
$auth      = new AuthController();
$dashboard = new DashboardController();
$oauth     = new OAuthController();
$clients   = new ClientsController();
$users     = new UsersController();
$docs      = new DocsController();

// Dashboard
$router->get('/',           fn() => $dashboard->dashboard());

// Auth
$router->get('/login',      fn() => $auth->loginForm());
$router->post('/login',     fn() => $auth->loginPost());
$router->get('/logout',     fn() => $auth->logout());

// OAuth2 Web UI (authorize consent)
$router->get('/authorize',  fn() => $oauth->authorizeGet());
$router->post('/authorize', fn() => $oauth->authorizePost());

// OAuth2 API endpoints (no session auth required — validate credentials internally)
$router->post('/token',     fn() => $oauth->token());
$router->post('/revoke',    fn() => $oauth->revoke());

// Admin management pages
$router->get('/clients',    fn() => $clients->clients());
$router->post('/clients',   fn() => $clients->clientsPost());
$router->get('/users',      fn() => $users->users());
$router->post('/users',     fn() => $users->usersPost());

// Docs
$router->get('/docs',       fn() => $docs->docs());

$router->dispatch();
