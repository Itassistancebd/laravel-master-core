<?php

namespace MasterCore\SystemHealth\Enums;

enum HealthCheckStatus: string
{
    case Healthy = 'healthy';
    case Unavailable = 'unavailable';
    case NotConfigured = 'not_configured';
}
