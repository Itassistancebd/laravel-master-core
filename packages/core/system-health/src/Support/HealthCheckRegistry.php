<?php

namespace MasterCore\SystemHealth\Support;

use MasterCore\SystemHealth\Contracts\HealthCheck;
use MasterCore\SystemHealth\Enums\HealthCheckStatus;

final readonly class HealthCheckRegistry
{
    /** @param list<HealthCheck> $checks This list is assembled in code, never from request input. */
    public function __construct(private array $checks) {}

    public function run(): array
    {
        return array_map(static fn (HealthCheck $check): array => $check->run()->toArray(), $this->checks);
    }

    public function overallStatus(array $results): string
    {
        $statuses = array_column($results, 'status');

        if (in_array(HealthCheckStatus::Unavailable->value, $statuses, true)) {
            return HealthCheckStatus::Unavailable->value;
        }
        if (in_array(HealthCheckStatus::NotConfigured->value, $statuses, true) || $statuses === []) {
            return HealthCheckStatus::NotConfigured->value;
        }

        return HealthCheckStatus::Healthy->value;
    }
}
