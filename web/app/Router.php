<?php

declare(strict_types=1);

namespace GfServer\App;

/**
 * Exact-match router: maps "METHOD /path" to a [controller, action] pair.
 * Path parameters are not used — dynamic pages take ids via the query string.
 */
final class Router
{
    /** @var array<string, array{0: string, 1: string}> */
    private array $routes = [];

    public function add(string $method, string $path, string $controller, string $action): void
    {
        $this->routes[$this->key($method, $path)] = [$controller, $action];
    }

    /** @return array{0: string, 1: string}|null */
    public function match(string $method, string $path): ?array
    {
        return $this->routes[$this->key($method, $path)] ?? null;
    }

    private function key(string $method, string $path): string
    {
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        return strtoupper($method) . ' ' . $path;
    }
}
