<?php

namespace MasterCore\SystemHealth\Data;

use MasterCore\SystemHealth\Enums\HealthCheckStatus;

final readonly class HealthCheckResult
{
    public function __construct(
        public string $key,
        public string $name,
        public HealthCheckStatus $status,
        public string $message,
    ) {}

    /** Keep result fields and failure wording within the package's response allowlist. */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'status' => $this->status->value,
            'outcome' => match ($this->status) {
                HealthCheckStatus::Healthy => 'successful',
                HealthCheckStatus::Unavailable => 'failed',
                HealthCheckStatus::NotConfigured => 'not_configured',
            },
            'message' => $this->message,
        ];
    }
}
