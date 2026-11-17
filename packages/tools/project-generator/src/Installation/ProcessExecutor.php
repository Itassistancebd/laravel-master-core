<?php

namespace MasterCore\ProjectGenerator\Installation;

interface ProcessExecutor
{
    /** @param list<string> $arguments @param array<string, string>|null $environment */
    public function run(array $arguments, string $workingDirectory, ?array $environment = null): void;
}
