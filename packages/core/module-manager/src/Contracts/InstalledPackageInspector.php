<?php

namespace MasterCore\ModuleManager\Contracts;

interface InstalledPackageInspector
{
    /** Return only installation and version facts for the requested allowlisted package name. */
    public function inspect(string $packageName): array;
}
