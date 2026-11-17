<?php

namespace MasterCore\ModuleManager\Contracts;

interface ModuleCatalog
{
    /** @return array<int, array<string, mixed>> */
    public function all(): array;

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array;
}
