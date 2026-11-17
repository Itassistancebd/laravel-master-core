<?php

namespace MasterCore\AuditLogs\Support;

/** The closed event catalog prevents request input from inventing audit event names. */
final class AuditEventCatalog
{
    /** @var array<string, array{category: string, metadata: list<string>, snapshots: list<string>}> */
    private const EVENTS = [
        'auth.login.succeeded' => ['category' => 'authentication', 'metadata' => [], 'snapshots' => []],
        'auth.login.failed' => ['category' => 'authentication', 'metadata' => [], 'snapshots' => []],
        'auth.lockout' => ['category' => 'authentication', 'metadata' => [], 'snapshots' => []],
        'auth.logout' => ['category' => 'authentication', 'metadata' => [], 'snapshots' => []],
        'auth.password_reset.completed' => ['category' => 'authentication', 'metadata' => [], 'snapshots' => []],
        'auth.email_verified' => ['category' => 'authentication', 'metadata' => [], 'snapshots' => []],
        'auth.session.revoked' => ['category' => 'authentication', 'metadata' => ['session_count'], 'snapshots' => []],
        'user.created' => ['category' => 'user_management', 'metadata' => ['changed_fields'], 'snapshots' => []],
        'user.updated' => ['category' => 'user_management', 'metadata' => ['changed_fields'], 'snapshots' => []],
        'user.activated' => ['category' => 'user_management', 'metadata' => [], 'snapshots' => ['is_active']],
        'user.deactivated' => ['category' => 'user_management', 'metadata' => [], 'snapshots' => ['is_active']],
        'user.trashed' => ['category' => 'user_management', 'metadata' => [], 'snapshots' => []],
        'user.restored' => ['category' => 'user_management', 'metadata' => [], 'snapshots' => []],
        'user.permanently_deleted' => ['category' => 'user_management', 'metadata' => [], 'snapshots' => []],
        'user.password_reset_requested' => ['category' => 'user_management', 'metadata' => [], 'snapshots' => []],
        'user.verification_resent' => ['category' => 'user_management', 'metadata' => [], 'snapshots' => []],
        'user.exported' => ['category' => 'user_management', 'metadata' => ['record_count', 'fields', 'filter_keys'], 'snapshots' => []],
        'user.import.completed' => ['category' => 'user_management', 'metadata' => ['imported_rows', 'skipped_rows', 'failed_rows'], 'snapshots' => []],
        'role.created' => ['category' => 'roles_permissions', 'metadata' => ['role_key'], 'snapshots' => []],
        'role.renamed' => ['category' => 'roles_permissions', 'metadata' => ['role_key'], 'snapshots' => []],
        'role.deleted' => ['category' => 'roles_permissions', 'metadata' => ['role_key'], 'snapshots' => []],
        'role.permissions_synced' => ['category' => 'roles_permissions', 'metadata' => ['role_key', 'granted_permissions', 'revoked_permissions'], 'snapshots' => []],
        'user.roles_synced' => ['category' => 'roles_permissions', 'metadata' => ['assigned_roles', 'removed_roles'], 'snapshots' => []],
        'permission.materialized' => ['category' => 'roles_permissions', 'metadata' => ['permission_name'], 'snapshots' => []],
        'permission.metadata_updated' => ['category' => 'roles_permissions', 'metadata' => ['permission_name', 'changed_fields'], 'snapshots' => []],
        'permission.deleted' => ['category' => 'roles_permissions', 'metadata' => ['permission_name'], 'snapshots' => []],
        'super_admin.provisioned' => ['category' => 'roles_permissions', 'metadata' => ['role_key'], 'snapshots' => []],
        'settings.group_updated' => ['category' => 'settings', 'metadata' => ['group', 'changed_keys'], 'snapshots' => []],
        'settings.group_reset' => ['category' => 'settings', 'metadata' => ['group', 'changed_keys'], 'snapshots' => []],
        'settings.brand_asset_changed' => ['category' => 'settings', 'metadata' => ['asset_type'], 'snapshots' => []],
        'category.created' => ['category' => 'categories', 'metadata' => ['changed_fields'], 'snapshots' => []],
        'category.updated' => ['category' => 'categories', 'metadata' => ['changed_fields'], 'snapshots' => []],
        'category.deleted' => ['category' => 'categories', 'metadata' => [], 'snapshots' => []],
    ];

    /** @return array{category: string, metadata: list<string>, snapshots: list<string>}|null */
    public function definition(string $eventName): ?array
    {
        return self::EVENTS[$eventName] ?? null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys(self::EVENTS);
    }
}
