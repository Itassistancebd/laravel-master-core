<?php

namespace MasterCore\AuditLogs\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Log;
use MasterCore\AuditLogs\Contracts\AuditLogger;
use Throwable;

final class AuthenticationAuditListener
{
    public function login(Login $event): void
    {
        $this->safe('auth.login.succeeded', $event->user);
    }

    public function failed(Failed $event): void
    {
        $this->safe('auth.login.failed', null, 'failed');
    }

    public function lockout(Lockout $event): void
    {
        $this->safe('auth.lockout', null, 'denied');
    }

    public function logout(Logout $event): void
    {
        $this->safe('auth.logout', $event->user);
    }

    public function passwordReset(PasswordReset $event): void
    {
        $this->safe('auth.password_reset.completed', $event->user);
    }

    public function verified(Verified $event): void
    {
        $this->safe('auth.email_verified', $event->user);
    }

    private function safe(string $name, mixed $subject, string $outcome = 'succeeded'): void
    {
        try {
            app(AuditLogger::class)->record([
                'event_name' => $name,
                'actor' => $subject,
                'subject' => $subject,
                'outcome' => $outcome,
            ]);
        } catch (Throwable $exception) {
            // Authentication availability takes priority; never log event payloads, exception text, or credentials.
            try {
                Log::warning('Master Core authentication audit write failed.', [
                    'event_name' => $name,
                    'exception_class' => $exception::class,
                ]);
            } catch (Throwable) {
                // A broken operational log channel must not turn audit failure into an auth outage.
            }
        }
    }
}
