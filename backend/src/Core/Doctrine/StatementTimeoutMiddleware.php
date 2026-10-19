<?php

namespace App\Core\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Postgres gives up on a statement after STATEMENT_TIMEOUT, so one heavy
 * request can't keep the database busy for every other user (and keep going
 * after the browser left). Console commands (migrations, the import, the
 * scheduler) are trusted and may run longer: the limit is for web requests.
 */
#[AsMiddleware]
final readonly class StatementTimeoutMiddleware implements Middleware
{
    public const string STATEMENT_TIMEOUT = '30s';

    public function __construct(private bool $enabled = \PHP_SAPI !== 'cli')
    {
    }

    public function wrap(Driver $driver): Driver
    {
        if (!$this->enabled) {
            return $driver;
        }

        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);
                $connection->exec(\sprintf("SET statement_timeout = '%s'", StatementTimeoutMiddleware::STATEMENT_TIMEOUT));

                return $connection;
            }
        };
    }
}
