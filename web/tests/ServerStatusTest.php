<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\ServerStatus;

final class ServerStatusTest extends DbTestCase
{
    private ServerStatus $status;

    protected function setUp(): void
    {
        parent::setUp();
        $this->status = new ServerStatus($this->db);
    }

    public function testIsPortOpenReturnsFalseForAClosedPort(): void
    {
        // Port 1 on localhost is not listening in the CI container.
        $this->assertFalse($this->status->isPortOpen('127.0.0.1', 1, 0.5));
    }

    public function testAccountCountIsANonNegativeInteger(): void
    {
        $count = $this->status->accountCount();
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testCharacterCountIsANonNegativeInteger(): void
    {
        $count = $this->status->characterCount();
        $this->assertGreaterThanOrEqual(0, $count);
    }
}
