<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\RateLimiter;

final class RateLimiterTest extends DbTestCase
{
    private RateLimiter $limiter;
    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = new RateLimiter($this->db);
        $this->ip = '203.0.113.' . random_int(2, 254);
    }

    protected function tearDown(): void
    {
        $this->adminPdo('gf_ls')
            ->prepare('DELETE FROM web_login_attempt WHERE ip = :ip')
            ->execute([':ip' => $this->ip]);
    }

    public function testFailuresAccumulateAndTripTheLimit(): void
    {
        $this->assertFalse($this->limiter->tooManyFailures($this->ip, 3, 3600));

        $this->limiter->record($this->ip, false);
        $this->limiter->record($this->ip, false);
        $this->assertFalse($this->limiter->tooManyFailures($this->ip, 3, 3600));

        $this->limiter->record($this->ip, false);
        $this->assertTrue($this->limiter->tooManyFailures($this->ip, 3, 3600));
    }

    public function testSuccessfulAttemptsDoNotCountAsFailures(): void
    {
        $this->limiter->record($this->ip, true);
        $this->limiter->record($this->ip, true);
        $this->assertFalse($this->limiter->tooManyFailures($this->ip, 1, 3600));
    }
}
