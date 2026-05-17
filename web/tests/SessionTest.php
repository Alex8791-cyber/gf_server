<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\App\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_SESSION'] = [];
    }

    public function testSetAndGet(): void
    {
        $session = new Session();
        $session->set('key', 'value');
        $this->assertSame('value', $session->get('key'));
    }

    public function testGetMissingKeyReturnsNull(): void
    {
        $this->assertNull((new Session())->get('absent'));
    }

    public function testRemove(): void
    {
        $session = new Session();
        $session->set('key', 'value');
        $session->remove('key');
        $this->assertNull($session->get('key'));
    }
}
