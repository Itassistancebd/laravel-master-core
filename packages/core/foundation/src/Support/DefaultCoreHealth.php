<?php

namespace MasterCore\Foundation\Support;

use Illuminate\Contracts\Config\Repository;
use MasterCore\Foundation\Contracts\CoreHealthInterface;

final class DefaultCoreHealth implements CoreHealthInterface
{
    public function __construct(private readonly Repository $config) {}

    public function status(): array
    {
        return [
            'name' => (string) $this->config->get('master-core.name', 'Master Core'),
            'version' => (string) $this->config->get('master-core.version', '1.0.0'),
            'status' => 'healthy',
        ];
    }
}
