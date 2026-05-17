<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\App\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testGenerateReturnsA64CharHexToken(): void
    {
        $token = Csrf::generate();
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testGenerateReturnsADifferentTokenEachCall(): void
    {
        $this->assertNotSame(Csrf::generate(), Csrf::generate());
    }

    public function testCheckAcceptsAMatchingToken(): void
    {
        $token = Csrf::generate();
        $this->assertTrue(Csrf::check($token, $token));
    }

    public function testCheckRejectsMismatchAndNulls(): void
    {
        $this->assertFalse(Csrf::check(Csrf::generate(), Csrf::generate()));
        $this->assertFalse(Csrf::check(null, 'x'));
        $this->assertFalse(Csrf::check('x', null));
        $this->assertFalse(Csrf::check('', ''));
    }
}
