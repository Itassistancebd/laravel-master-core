<?php

declare(strict_types=1);

require __DIR__ . '/Identity.php';
define('P07_REPORT_LIBRARY_ONLY', true);
require __DIR__ . '/run.php';

$checks = \P07\Identity::selfChecks();
$failed = array_filter($checks, static fn (array $check): bool => $check['status'] !== 'PASS');
foreach ($checks as $check) {
    echo $check['status'] . ': ' . $check['name'] . PHP_EOL;
}

echo sprintf("%d database-free policy checks, %d failed. No database connection was attempted.\n", count($checks), count($failed));
exit($failed === [] ? 0 : 1);
