<?php

namespace MasterCore\AuditLogs\Contracts;

interface AuditLogger
{
    /**
     * Persist a catalogued audit envelope synchronously on the current connection.
     * Critical producers call this inside their business transaction.
     *
     * @param  array<string, mixed>  $event
     */
    public function record(array $event): void;
}
