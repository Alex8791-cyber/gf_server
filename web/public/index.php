<?php

declare(strict_types=1);

use GfServer\AccountService;
use GfServer\AdminService;
use GfServer\App\Auth;
use GfServer\App\Controller\AccountAdminController;
use GfServer\App\Controller\AccountController;
use GfServer\App\Controller\ConfirmController;
use GfServer\App\Controller\DashboardController;
use GfServer\App\Controller\DownloadsController;
use GfServer\App\Controller\HomeController;
use GfServer\App\Controller\LoginController;
use GfServer\App\Controller\NewsAdminController;
use GfServer\App\Controller\NewsController;
use GfServer\App\Controller\PasswordResetController;
use GfServer\App\Controller\PlayerAdminController;
use GfServer\App\Controller\RankingController;
use GfServer\App\Controller\RegisterController;
use GfServer\App\Controller\StatusController;
use GfServer\App\Response;
use GfServer\App\Router;
use GfServer\App\Session;
use GfServer\App\SmtpMailer;
use GfServer\App\View;
use GfServer\Config;
use GfServer\Database;
use GfServer\NewsRepository;
use GfServer\RankingRepository;
use GfServer\RateLimiter;
use GfServer\ServerStatus;
use GfServer\TokenService;

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

// Make the logged-in user's name available to every template (the nav).
$navUsername = $session->get(Auth::SESSION_USERNAME);
$view->share([
    'navLoggedIn' => is_string($navUsername) && $navUsername !== '',
    'navUsername' => is_string($navUsername) ? $navUsername : '',
]);

$router = new Router();
$router->add('GET', '/', 'HomeController', 'index');
$router->add('GET', '/news', 'NewsController', 'index');
$router->add('GET', '/downloads', 'DownloadsController', 'index');
$router->add('GET', '/status', 'StatusController', 'index');
$router->add('GET', '/rankings', 'RankingController', 'index');
$router->add('GET', '/register', 'RegisterController', 'showForm');
$router->add('POST', '/register', 'RegisterController', 'submit');
$router->add('GET', '/confirm', 'ConfirmController', 'confirm');
$router->add('GET', '/login', 'LoginController', 'showForm');
$router->add('POST', '/login', 'LoginController', 'submit');
$router->add('POST', '/logout', 'LoginController', 'logout');
$router->add('GET', '/account', 'AccountController', 'home');
$router->add('GET', '/account/password', 'AccountController', 'showPasswordForm');
$router->add('POST', '/account/password', 'AccountController', 'changePassword');
$router->add('GET', '/forgot', 'PasswordResetController', 'showRequestForm');
$router->add('POST', '/forgot', 'PasswordResetController', 'submitRequest');
$router->add('GET', '/reset', 'PasswordResetController', 'showResetForm');
$router->add('POST', '/reset', 'PasswordResetController', 'submitReset');
$router->add('GET', '/admin', 'DashboardController', 'index');
$router->add('GET', '/admin/news', 'NewsAdminController', 'index');
$router->add('GET', '/admin/news/new', 'NewsAdminController', 'createForm');
$router->add('POST', '/admin/news/new', 'NewsAdminController', 'create');
$router->add('GET', '/admin/news/edit', 'NewsAdminController', 'editForm');
$router->add('POST', '/admin/news/edit', 'NewsAdminController', 'update');
$router->add('POST', '/admin/news/publish', 'NewsAdminController', 'togglePublish');
$router->add('POST', '/admin/news/delete', 'NewsAdminController', 'delete');
$router->add('GET', '/admin/players', 'PlayerAdminController', 'index');
$router->add('POST', '/admin/players/gm', 'PlayerAdminController', 'setGm');
$router->add('POST', '/admin/players/rename', 'PlayerAdminController', 'renamePlayer');
$router->add('POST', '/admin/players/sprite', 'PlayerAdminController', 'renameSprite');
$router->add('GET', '/admin/accounts', 'AccountAdminController', 'index');
$router->add('POST', '/admin/accounts/password', 'AccountAdminController', 'resetPassword');
$router->add('POST', '/admin/accounts/lock', 'AccountAdminController', 'setLocked');
$router->add('POST', '/admin/accounts/resend', 'AccountAdminController', 'resendConfirmation');

// Lazily open the database only when a controller needs it.
$db = null;
$getDb = static function () use (&$db): Database {
    if ($db === null) {
        $db = new Database(Config::fromEnv());
    }

    return $db;
};

/**
 * Instantiate a controller by name with its dependencies. Extended by later
 * plans as more controllers are added.
 */
$makeController = static function (string $name) use ($view, $session, $getDb): object {
    return match ($name) {
        'HomeController' => new HomeController($view),
        'NewsController' => new NewsController($view, new NewsRepository($getDb())),
        'DownloadsController' => new DownloadsController($view),
        'StatusController' => new StatusController($view, new ServerStatus($getDb())),
        'RankingController' => new RankingController($view, new RankingRepository($getDb())),
        'RegisterController' => new RegisterController(
            $view,
            $session,
            new AccountService($getDb()),
            new TokenService($getDb()),
            SmtpMailer::fromEnv(),
            new RateLimiter($getDb()),
        ),
        'ConfirmController' => new ConfirmController(
            $view,
            $session,
            new TokenService($getDb()),
            new AccountService($getDb()),
        ),
        'LoginController' => new LoginController(
            $view,
            $session,
            new AccountService($getDb()),
            new Auth($session, $getDb()),
            new RateLimiter($getDb()),
        ),
        'AccountController' => new AccountController(
            $view,
            $session,
            new Auth($session, $getDb()),
            new AccountService($getDb()),
        ),
        'PasswordResetController' => new PasswordResetController(
            $view,
            $session,
            new AccountService($getDb()),
            new TokenService($getDb()),
            SmtpMailer::fromEnv(),
            new RateLimiter($getDb()),
        ),
        'DashboardController' => new DashboardController(
            $view,
            $session,
            new Auth($session, $getDb()),
            new ServerStatus($getDb()),
        ),
        'NewsAdminController' => new NewsAdminController(
            $view,
            $session,
            new Auth($session, $getDb()),
            new NewsRepository($getDb()),
        ),
        'PlayerAdminController' => new PlayerAdminController(
            $view,
            $session,
            new Auth($session, $getDb()),
            new AdminService($getDb()),
        ),
        'AccountAdminController' => new AccountAdminController(
            $view,
            $session,
            new Auth($session, $getDb()),
            new AccountService($getDb()),
            new TokenService($getDb()),
            SmtpMailer::fromEnv(),
        ),
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
