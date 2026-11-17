<?php

namespace MasterCore\ProjectGenerator\Installation;

final class TargetPath
{
    public static function validate(string $target): string
    {
        $target = trim($target);
        if ($target === '' || str_contains($target, "\0")) {
            throw new InstallationException('A non-empty target path is required.');
        }

        $absolute = self::absolutePath($target);
        if (file_exists($absolute) || is_link($absolute)) {
            throw new InstallationException('Target already exists. Existing project files are never overwritten.');
        }

        $parent = realpath(dirname($absolute));
        if ($parent === false || ! is_dir($parent) || ! is_writable($parent)) {
            throw new InstallationException('Target parent directory must already exist and be writable.');
        }

        $name = basename($absolute);
        if ($name === '' || $name === '.' || $name === '..') {
            throw new InstallationException('Target path must name a new project directory.');
        }

        return $parent.DIRECTORY_SEPARATOR.$name;
    }

    private static function absolutePath(string $path): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2})/', $path) === 1) {
            return rtrim($path, '\\/');
        }

        return getcwd().DIRECTORY_SEPARATOR.$path;
    }
}
