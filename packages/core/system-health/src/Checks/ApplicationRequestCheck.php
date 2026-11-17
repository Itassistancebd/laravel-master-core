<?php

namespace MasterCore\SystemHealth\Checks;

use MasterCore\SystemHealth\Contracts\HealthCheck;
use MasterCore\SystemHealth\Data\HealthCheckResult;
use MasterCore\SystemHealth\Enums\HealthCheckStatus;

final class ApplicationRequestCheck implements HealthCheck
{
    public function run(): HealthCheckResult
    {
        // Reaching this controller proves only that this authenticated request was handled.
        return new HealthCheckResult(
            key: 'application_request',
            name: 'Application request',
            status: HealthCheckStatus::Healthy,
            message: 'The authenticated diagnostic request was handled.',
        );
    }
}
