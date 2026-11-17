<?php

namespace MasterCore\SystemHealth\Contracts;

use MasterCore\SystemHealth\Data\HealthCheckResult;

interface HealthCheck
{
    public function run(): HealthCheckResult;
}
