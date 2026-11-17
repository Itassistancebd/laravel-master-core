<?php

namespace MasterCore\ModuleManager\Support;

use Composer\InstalledVersions;
use MasterCore\ModuleManager\Contracts\InstalledPackageInspector;
use OutOfBoundsException;

final class ComposerInstalledPackageInspector implements InstalledPackageInspector
{
    public function inspect(string $packageName): array
    {
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled($packageName)) {
            return ['installed' => false, 'version' => null];
        }

        try {
            $version = InstalledVersions::getPrettyVersion($packageName);
        } catch (OutOfBoundsException) {
            // A package may be replaced/provided by Composer without its own install version.
            $version = null;
        }

        return ['installed' => true, 'version' => $version];
    }
}
