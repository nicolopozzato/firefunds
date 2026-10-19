<?php

namespace App\Tests\Unit\Core;

use App\Core\Doctrine\StatementTimeoutMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use PHPUnit\Framework\TestCase;

class StatementTimeoutMiddlewareTest extends TestCase
{
    public function testWebConnectionsGetTheTimeout(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('exec')->with("SET statement_timeout = '30s'");

        (new StatementTimeoutMiddleware(enabled: true))->wrap($this->driver($connection))->connect([]);
    }

    public function testConsoleConnectionsAreLeftAlone(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('exec');

        (new StatementTimeoutMiddleware(enabled: false))->wrap($this->driver($connection))->connect([]);
    }

    private function driver(Connection $connection): Driver
    {
        $driver = $this->createStub(Driver::class);
        $driver->method('connect')->willReturn($connection);

        return $driver;
    }
}
