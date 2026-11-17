<?php

namespace MasterCore\Foundation\Contracts;

interface CoreHealthInterface
{
    /**
     * Return the public, non-sensitive health payload.
     *
     * @return array{name: string, version: string, status: string}
     */
    public function status(): array;
}
