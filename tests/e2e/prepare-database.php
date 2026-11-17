<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MasterCore\RolesPermissions\Models\Role;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$adminEmail = (string) getenv('PLAYWRIGHT_ADMIN_EMAIL');
$adminPassword = (string) getenv('PLAYWRIGHT_ADMIN_PASSWORD');
$viewerEmail = (string) getenv('PLAYWRIGHT_VIEWER_EMAIL');
$viewerPassword = (string) getenv('PLAYWRIGHT_VIEWER_PASSWORD');

if ($adminEmail === '' || $adminPassword === '' || $viewerEmail === '' || $viewerPassword === '') {
    fwrite(STDERR, "Browser test credentials were not prepared in process environment.\n");
    exit(2);
}

DB::transaction(function () use ($adminEmail, $adminPassword, $viewerEmail, $viewerPassword): void {
    $now = now();
    $admin = User::query()->forceCreate([
        'name' => 'Playwright QA Admin',
        'email' => $adminEmail,
        'password' => Hash::make($adminPassword),
        'email_verified_at' => $now,
        'is_active' => true,
    ]);
    $viewer = User::query()->forceCreate([
        'name' => 'Playwright QA Viewer',
        'email' => $viewerEmail,
        'password' => Hash::make($viewerPassword),
        'email_verified_at' => $now,
        'is_active' => true,
    ]);

    foreach (range(1, 31) as $index) {
        $name = 'QA Category '.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
        DB::table('categories')->insert([
            'name' => $name,
            'slug' => 'qa-category-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'description' => 'Isolated browser QA fixture.',
            'status' => $index % 2 === 0 ? 'inactive' : 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $role = Role::query()->forceCreate([
        'name' => 'Core Super Admin',
        'role_key' => 'core-super-admin',
        'guard_name' => 'web',
        'is_system' => true,
    ]);

    DB::table('master_core_model_has_roles')->insert([
        'role_id' => $role->getKey(),
        'model_type' => $admin->getMorphClass(),
        'model_id' => (string) $admin->getKey(),
    ]);

    DB::table('users')->where('id', $viewer->getKey())->update(['is_active' => true]);

    for ($index = 1; $index <= 36; $index++) {
        User::query()->forceCreate([
            'name' => ($index === 1 ? '=1+1 ' : '').'Phase15 Export QA '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'email' => sprintf('phase15-export-%02d@example.test', $index),
            'password' => Hash::make(bin2hex(random_bytes(24))),
            'email_verified_at' => $now,
            'is_active' => true,
        ]);
    }

    User::query()->forceCreate([
        'name' => 'Phase15 Export QA Inactive',
        'email' => 'phase15-export-inactive@example.test',
        'password' => Hash::make(bin2hex(random_bytes(24))),
        'email_verified_at' => $now,
        'is_active' => false,
    ]);
    User::query()->forceCreate([
        'name' => 'Phase15 Export QA Unverified',
        'email' => 'phase15-export-unverified@example.test',
        'password' => Hash::make(bin2hex(random_bytes(24))),
        'email_verified_at' => null,
        'is_active' => true,
    ]);
});

fwrite(STDOUT, "Isolated browser test database prepared.\n");
