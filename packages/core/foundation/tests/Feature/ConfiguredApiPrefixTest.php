<?php

namespace MasterCore\Foundation\Tests\Feature;

use Tests\TestCase;

class ConfiguredApiPrefixTest extends TestCase
{
    public function createApplication()
    {
        $previous = [];

        foreach (['MASTER_CORE_API_PREFIX' => 'service', 'MASTER_CORE_API_VERSION' => 'v2'] as $key => $value) {
            $previous[$key] = [
                'environment' => getenv($key),
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
            ];

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            return parent::createApplication();
        } finally {
            foreach ($previous as $key => $values) {
                $values['environment'] === false ? putenv($key) : putenv("{$key}={$values['environment']}");

                if ($values['env'] === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $values['env'];
                }

                if ($values['server'] === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $values['server'];
                }
            }
        }
    }

    public function test_api_prefix_and_version_can_be_configured(): void
    {
        $this->getJson('/service/v2/core/health')->assertOk();
    }
}
