<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use MasterCore\AuditLogs\Models\AuditEvent;
use MasterCore\ModuleManager\Contracts\ModuleCatalog;
use MasterCore\RolesPermissions\Models\Permission;
use MasterCore\RolesPermissions\Models\Role;
use MasterCore\RolesPermissions\Support\HostUserModelResolver;
use MasterCore\SystemHealth\Support\HealthCheckRegistry;
use MasterCore\UserManagement\Support\UserModelResolver;

final class DashboardController
{
    public function __invoke(
        Request $request,
        UserModelResolver $users,
        HostUserModelResolver $guards,
        ModuleCatalog $modules,
        HealthCheckRegistry $healthChecks,
    ): JsonResponse {
        $user = $request->user();
        $can = static fn (string $ability): bool => Gate::forUser($user)->allows($ability);
        $access = [
            'users' => $can('view-users'),
            'roles' => $can('view-roles'),
            'permissions' => $can('view-permissions'),
            'audit' => $can('view-audit-logs'),
            'modules' => $can('view-modules'),
            'health' => $can('view-system-health'),
            'settings' => $can('view-settings'),
            'create_users' => $can('create-users') || $can('manage-users'),
            'manage_roles' => $can('create-roles') || $can('edit-roles'),
        ];

        $metrics = [
            'users' => null,
            'active_users' => null,
            'inactive_users' => null,
            'roles' => null,
            'permissions' => null,
            'modules' => null,
        ];
        $growth = [];

        if ($access['users']) {
            $query = $users->newQuery();
            $totals = (clone $query)->selectRaw(
                'COUNT(*) as total_users, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users, SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_users'
            )->first();
            $metrics['users'] = (int) $totals->total_users;
            $metrics['active_users'] = (int) $totals->active_users;
            $metrics['inactive_users'] = (int) $totals->inactive_users;

            $connection = DB::connection($query->getModel()->getConnectionName());
            $monthExpression = match ($connection->getDriverName()) {
                'mysql', 'mariadb' => "DATE_FORMAT(created_at, '%Y-%m')",
                'sqlite' => "strftime('%Y-%m', created_at)",
                'pgsql' => "to_char(created_at, 'YYYY-MM')",
                'sqlsrv' => "FORMAT(created_at, 'yyyy-MM')",
                default => throw new \RuntimeException('The configured database driver cannot group dashboard user growth by month.'),
            };
            $firstMonth = Carbon::now()->startOfMonth()->subMonths(5);
            $countsByMonth = (clone $query)
                ->selectRaw("{$monthExpression} as month_key, COUNT(*) as monthly_total")
                ->whereBetween('created_at', [$firstMonth, Carbon::now()->endOfMonth()])
                ->groupByRaw($monthExpression)
                ->pluck('monthly_total', 'month_key');

            for ($offset = 5; $offset >= 0; $offset--) {
                $month = $firstMonth->copy()->addMonths(5 - $offset);
                $growth[] = [
                    'label' => $month->format('M'),
                    'count' => (int) ($countsByMonth[$month->format('Y-m')] ?? 0),
                ];
            }
        }

        $guard = $guards->guardName();
        if ($access['roles']) {
            $metrics['roles'] = Role::query()->where('guard_name', $guard)->count();
        }
        if ($access['permissions']) {
            $metrics['permissions'] = Permission::query()->where('guard_name', $guard)->count();
        }

        $moduleRows = [];
        if ($access['modules']) {
            $moduleRows = $modules->all();
            $metrics['modules'] = count($moduleRows);
        }

        $activity = [];
        if ($access['audit']) {
            $activity = AuditEvent::query()
                ->select(['id', 'event_name', 'category', 'outcome', 'actor_type', 'actor_id', 'occurred_at'])
                ->latest('occurred_at')
                ->limit(6)
                ->get()
                ->map(static fn (AuditEvent $event): array => [
                    'id' => $event->id,
                    'event_name' => $event->event_name,
                    'category' => $event->category,
                    'outcome' => $event->outcome,
                    'actor' => $event->actor_id === null ? null : [
                        'type' => $event->actor_type,
                        'id' => $event->actor_id,
                    ],
                    'occurred_at' => $event->occurred_at?->utc()->toIso8601String(),
                ])->all();
        }

        $health = null;
        if ($access['health']) {
            $results = $healthChecks->run();
            $health = [
                'overall_status' => $healthChecks->overallStatus($results),
                'checks' => array_map(static fn (array $check): array => [
                    'name' => $check['name'] ?? 'System check',
                    'status' => $check['status'] ?? 'not_configured',
                ], $results),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'generated_at' => now()->toIso8601String(),
                'current_user' => ['name' => $user->name ?? $user->email],
                'access' => $access,
                'metrics' => $metrics,
                'user_growth' => $growth,
                'recent_activity' => $activity,
                'health' => $health,
                'module_readiness' => array_count_values(array_column($moduleRows, 'readiness_status')),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }
}
