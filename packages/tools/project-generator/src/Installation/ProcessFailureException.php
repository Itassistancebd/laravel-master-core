<?php

namespace MasterCore\ProjectGenerator\Installation;

use RuntimeException;

final class ProcessFailureException extends RuntimeException
{
    public function __construct(string $message, public readonly int $exitCode)
    {
        parent::__construct($message);
    }
}
