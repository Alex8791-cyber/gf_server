<?php

declare(strict_types=1);

use GfServer\App\Controller\HomeController;
use GfServer\App\Response;
use GfServer\App\Router;
use GfServer\App\Session;
use GfServer\App\View;

require __DIR__ . '/../vendor/autoload.php';

// --- Security headers ------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'");

// --- Wiring ----------------------------------------------------------------
$session = new Session();
$session->start();

$view = new View(__DIR__ . '/../templates');

$router = new Router();
$router->add('GET', '/', 'HomeController', 'index');

/**
 * Instantiate a controller by name with its dependencies. Extended by later
 * plans as more controllers are added.
 */
$makeController = static function (string $name) use ($view): object {
    return match ($name) {
        'HomeController' => new HomeController($view),
        default => throw new \RuntimeException("Unknown controller: {$name}"),
    };
};

// --- Dispatch --------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$route = $router->match($method, $path);

try {
    if ($route === null) {
        $response = Response::html($view->render('home', ['title' => 'Not found']), 404);
    } else {
        [$controllerName, $action] = $route;
        $controller = $makeController($controllerName);
        $response = $controller->$action();
    }
} catch (\Throwable $e) {
    error_log('Portal error: ' . $e->getMessage());
    $response = Response::html('<h1>Internal error</h1>', 500);
}

$response->send();
