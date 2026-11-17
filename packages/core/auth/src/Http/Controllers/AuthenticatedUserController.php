<?php

namespace MasterCore\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuthenticatedUserController
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'id' => $user->getAuthIdentifier(),
            'name' => $user->getAttribute('name'),
            'email' => $user->getAttribute('email'),
            'email_verified_at' => $user->getAttribute('email_verified_at'),
        ]]);
    }

    public function sessions(Request $request): JsonResponse
    {
        abort_unless($this->sessionInventoryAvailable(), 501, 'Session inventory requires the database session driver.');

        $userId = (string) $request->user()->getAuthIdentifier();
        $currentId = $request->session()->getId();
        $sessions = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->orderByDesc('last_activity')
            ->limit((int) config('master-core-auth.session_inventory.limit', 20))
            ->get();

        return response()->json(['data' => $sessions->map(fn ($session): array => [
            // Hash identifiers so a response cannot be used as a session cookie credential.
            'id' => hash('sha256', $session->id),
            'ip_address' => $session->ip_address,
            'user_agent' => $session->user_agent,
            'last_activity' => (int) $session->last_activity,
            'current' => hash_equals((string) $session->id, $currentId),
        ])]);
    }

    public function revokeSession(Request $request, string $session): JsonResponse
    {
        abort_unless($this->sessionInventoryAvailable(), 501, 'Session inventory requires the database session driver.');

        $currentId = $request->session()->getId();
        $table = config('session.table', 'sessions');
        $owned = DB::table($table)
            ->where('user_id', (string) $request->user()->getAuthIdentifier())
            ->get(['id']);
        $target = $owned->first(fn ($row): bool => hash_equals($session, hash('sha256', (string) $row->id)));

        abort_if($target === null || hash_equals((string) $target->id, $currentId), 404);

        DB::transaction(function () use ($table, $target, $request): void {
            DB::table($table)->where('id', $target->id)->delete();
            try {
                event('master-core.audit.record.v1', [[
                    'event_name' => 'auth.session.revoked',
                    'actor' => $request->user(),
                    'subject' => $request->user(),
                    'metadata' => ['session_count' => 1],
                ]]);
            } catch (Throwable $exception) {
                // Session revocation remains available if optional audit storage is unavailable.
                Log::warning('Master Core authentication audit write failed.', [
                    'event_name' => 'auth.session.revoked',
                    'exception_class' => $exception::class,
                ]);
            }
        });

        return response()->json(['message' => 'Session revoked.']);
    }

    private function sessionInventoryAvailable(): bool
    {
        return config('master-core-auth.session_inventory.enabled')
            && config('session.driver') === 'database'
            && DB::getSchemaBuilder()->hasTable(config('session.table', 'sessions'));
    }
}
