<?php

namespace MasterCore\SystemHealth\Checks;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use MasterCore\SystemHealth\Contracts\HealthCheck;
use MasterCore\SystemHealth\Data\HealthCheckResult;
use MasterCore\SystemHealth\Enums\HealthCheckStatus;
use Throwable;

final class DatabaseConnectivityCheck implements HealthCheck
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
    ) {}

    public function run(): HealthCheckResult
    {
        $defaultConnection = $this->config->get('database.default');
        $connections = $this->config->get('database.connections', []);

        if (! is_string($defaultConnection)
            || trim($defaultConnection) === ''
            || ! is_array($connections)
            || ! isset($connections[$defaultConnection])
            || ! is_array($connections[$defaultConnection])) {
            return new HealthCheckResult(
                key: 'primary_database',
                name: 'Primary database',
                status: HealthCheckStatus::NotConfigured,
                message: 'A default database connection is not configured.',
            );
        }

        try {
            // A direct PDO probe avoids Laravel's query retry path and performs only a constant read.
            $probe = $this->database->connection()->getPdo()->query('SELECT 1');
            if ($probe === false || (string) $probe->fetchColumn() !== '1') {
                throw new \RuntimeException('The database connectivity probe returned no result.');
            }

            return new HealthCheckResult(
                key: 'primary_database',
                name: 'Primary database',
                status: HealthCheckStatus::Healthy,
                message: 'The configured primary database answered the connectivity probe.',
            );
        } catch (Throwable $exception) {
            // Exception messages can contain hostnames, SQL, or driver configuration, so log only its class.
            try {
                Log::warning('Core system health database probe failed.', [
                    'check' => 'primary_database',
                    'exception_class' => $exception::class,
                ]);
            } catch (Throwable) {
                // Diagnostics must still return a safe unavailable result if the log channel also fails.
            }

            return new HealthCheckResult(
                key: 'primary_database',
                name: 'Primary database',
                status: HealthCheckStatus::Unavailable,
                message: 'The configured primary database did not answer the connectivity probe.',
            );
        }
    }
}
