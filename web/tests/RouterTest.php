<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\App\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchReturnsTheRegisteredHandler(): void
    {
        $router = new Router();
        $router->add('GET', '/', 'HomeController', 'index');

        $this->assertSame(['HomeController', 'index'], $router->match('GET', '/'));
    }

    public function testMatchReturnsNullForAnUnknownPath(): void
    {
        $router = new Router();
        $router->add('GET', '/', 'HomeController', 'index');

        $this->assertNull($router->match('GET', '/missing'));
        $this->assertNull($router->match('POST', '/'));
    }

    public function testTrailingSlashIsNormalisedAwayExceptRoot(): void
    {
        $router = new Router();
        $router->add('GET', '/news', 'NewsController', 'index');

        $this->assertSame(['NewsController', 'index'], $router->match('GET', '/news/'));
    }
}
