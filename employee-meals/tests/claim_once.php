<?php
/**
 * One till claiming today's subsidy through the real EntitlementEngine.
 *
 * Boots the Hyper POS application the same way an HTTP request does, so this
 * exercises the shipped plugin code - not a stand-in query.
 *
 *   php claim_once.php <till-number> <employee-id> <basket-total> <start-epoch-us>
 *
 * Several copies are started together by run-double-claim.sh and released at
 * the same microsecond, so the tills genuinely collide.
 */

[$self, $till, $employeeId, $basket, $startAt] = $argv + [null, '?', '1', '63.00', '0'];

// Composer's autoloader first - bootstrap/app.php assumes it is already
// loaded, and without it the failure reads as a missing framework class.
require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use EmployeeMeals\Models\Employee;
use EmployeeMeals\Support\EntitlementEngine;

$employee = Employee::query()->findOrFail((int) $employeeId);
$engine = app(EntitlementEngine::class);

// all tills fire together
while (microtime(true) * 1e6 < (float) $startAt) {
    usleep(200);
}

$t0 = microtime(true);
$result = $engine->claim($employee, (float) $basket, null, 'TILL-'.$till);
$ms = round((microtime(true) - $t0) * 1000, 1);

if ($result['claimed']) {
    printf("till %-2s  ALLOWED   subsidy R %6.2f applied        (%.1f ms)\n", $till, $result['subsidy'], $ms);
} else {
    $at = $result['entitlement']?->consumed_at?->format('H:i');
    printf("till %-2s  REFUSED   %-22s %s  (%.1f ms)\n", $till, $result['reason'], $at ?? '', $ms);
}
