<?php

namespace EmployeeMeals\Support;

use Carbon\CarbonInterface;
use EmployeeMeals\Models\Employee;
use EmployeeMeals\Models\Entitlement;
use EmployeeMeals\Models\LedgerEntry;
use EmployeeMeals\Models\SubsidyPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Is this employee entitled to a subsidised meal right now, and has today's
 * entitlement already been used?
 *
 * ── The one rule that matters ────────────────────────────────────────────
 * The claim is a SINGLE UPDATE statement whose WHERE clause carries the
 * business rule:
 *
 *     UPDATE ... SET status = 'consumed'
 *      WHERE employee = ? AND date = ? AND status IN ('available','reserved')
 *
 * An affected-row count of zero IS the refusal. InnoDB holds the row lock
 * until the transaction commits, so a second till blocks, then wakes to find
 * the status already moved on. It cannot be raced.
 *
 * ⚠️ Do NOT "simplify" this into read-the-status-then-decide-then-write. That
 * version looks identical in a single-user test and fails the moment a cashier
 * double-clicks or two tills scan the same card together - which is exactly
 * the failure that costs the employer real money.
 */
class EntitlementEngine
{
    /**
     * What the till should show when a card or finger identifies someone.
     * Read-only; nothing is claimed here.
     *
     * @return array{
     *     employee: Employee,
     *     entitled: bool,
     *     status: string,
     *     value: float,
     *     collected_at: ?CarbonInterface,
     *     reason: ?string
     * }
     */
    public function status(Employee $employee, ?CarbonInterface $on = null): array
    {
        $date = ($on ?? Carbon::now())->copy()->startOfDay();
        $plan = $employee->subsidyPlan;

        $base = [
            'employee' => $employee,
            'entitled' => false,
            'status' => 'none',
            'value' => 0.0,
            'collected_at' => null,
            'reason' => null,
        ];

        if (! $employee->is_active) {
            return ['reason' => 'inactive'] + $base;
        }
        if (! $employee->meal_entitled || $plan === null || ! $plan->is_active) {
            return ['reason' => 'no_plan'] + $base;
        }
        if (! $this->qualifiesToday($employee, $date)) {
            return ['reason' => 'not_a_qualifying_day'] + $base;
        }

        $entitlement = Entitlement::query()
            ->where('em_employee_id', $employee->id)
            ->where('entitlement_date', $date->toDateString())
            ->first();

        // Nothing recorded yet today - the meal is there to be taken.
        if ($entitlement === null) {
            return array_merge($base, [
                'entitled' => true,
                'status' => Entitlement::AVAILABLE,
                'value' => (float) $plan->daily_value,
            ]);
        }

        if ($entitlement->status === Entitlement::CONSUMED) {
            return array_merge($base, [
                'status' => Entitlement::CONSUMED,
                'value' => (float) $entitlement->value,
                'collected_at' => $entitlement->consumed_at,
                'reason' => 'already_collected',
            ]);
        }

        return array_merge($base, [
            'entitled' => $entitlement->isClaimable(),
            'status' => $entitlement->status,
            'value' => (float) $entitlement->value,
        ]);
    }

    /**
     * Take today's subsidy for this employee, once.
     *
     * @return array{claimed: bool, subsidy: float, entitlement: ?Entitlement, reason: ?string}
     */
    public function claim(
        Employee $employee,
        float $basketTotal,
        ?int $saleId = null,
        ?string $saleReference = null,
        ?CarbonInterface $on = null,
    ): array {
        $date = ($on ?? Carbon::now())->copy()->startOfDay();
        $plan = $employee->subsidyPlan;

        $status = $this->status($employee, $date);
        if (! $status['entitled']) {
            return [
                'claimed' => false,
                'subsidy' => 0.0,
                'entitlement' => null,
                'reason' => $status['reason'] ?? 'not_entitled',
            ];
        }

        // ⚠️ Both WHERE clauses below compare `entitlement_date` directly, never
        // whereDate(). whereDate() emits `date(entitlement_date) = ?`, which
        // cannot use the (employee, date) unique index - MySQL falls back to a
        // scan holding gap locks, and concurrent tills deadlock instead of one
        // simply being refused. Measured: 12 tills, deadlock every run; with the
        // plain comparison, none.
        //
        // The retry count is belt and braces on top of that.

        // 1. Make sure today's row exists - in its OWN committed statement,
        //    deliberately OUTSIDE the claim transaction below.
        //
        //    ⚠️ insertOrIgnore, NOT firstOrCreate: firstOrCreate is
        //    read-then-write, so two tills arriving together both read nothing,
        //    both insert, and the loser gets a raw duplicate-key exception -
        //    at a till that is an error page instead of "already collected".
        //
        //    ⚠️ And it must not share a transaction with the UPDATE. Ignoring a
        //    duplicate takes a SHARED lock on the existing row; the UPDATE then
        //    wants an EXCLUSIVE one, and a dozen tills all upgrading S→X inside
        //    their own transactions deadlock each other. Measured: with the
        //    insert inside the transaction, deadlocks on every run of 12 tills;
        //    committed separately first, none.
        $this->ensureTodaysRow($employee, $plan, $date);

        return DB::transaction(function () use ($employee, $plan, $date, $basketTotal, $saleId, $saleReference) {
            // 2. The claim. One statement; the WHERE clause is the rule.
            $claimed = Entitlement::query()
                ->where('em_employee_id', $employee->id)
                ->where('entitlement_date', $date->toDateString())
                ->whereIn('status', [Entitlement::AVAILABLE, Entitlement::RESERVED])
                ->update([
                    'status' => Entitlement::CONSUMED,
                    'consumed_at' => Carbon::now(),
                    'sale_id' => $saleId,
                    'sale_reference' => $saleReference,
                    'updated_at' => Carbon::now(),
                ]);

            $entitlement = Entitlement::query()
                ->where('em_employee_id', $employee->id)
                ->where('entitlement_date', $date->toDateString())
                ->first();

            if ($claimed !== 1) {
                // Someone else got there first, in this same instant.
                return [
                    'claimed' => false,
                    'subsidy' => 0.0,
                    'entitlement' => $entitlement,
                    'reason' => 'already_collected',
                ];
            }

            // 3. Work out the split, and write the ledger line that records
            //    who owed what. The subsidy can never exceed the basket - a
            //    R38 entitlement against a R20 purchase is worth R20.
            $subsidy = $this->subsidyFor($plan, $basketTotal, (float) $entitlement->value);
            $employeeOwes = max(0.0, round($basketTotal - $subsidy, 4));

            LedgerEntry::query()->create([
                'em_employee_id' => $employee->id,
                'em_entitlement_id' => $entitlement->id,
                'em_company_id' => $employee->em_company_id,
                'em_department_id' => $employee->em_department_id,
                'occurred_at' => Carbon::now(),
                'trade_date' => $date->toDateString(),
                'sale_id' => $saleId,
                'sale_reference' => $saleReference,
                'gross_amount' => $basketTotal,
                'subsidy_amount' => $subsidy,
                'employee_amount' => $employeeOwes,
                'settle_method' => $this->settleMethodFor($plan, $employeeOwes),
                // salary and company lines stay OPEN until payroll runs or the
                // company settles - that open set is the month-end list
                'is_settled' => ! ($plan->settlesLater() && $employeeOwes > 0),
                'settled_at' => $plan->settlesLater() && $employeeOwes > 0 ? null : Carbon::now(),
            ]);

            return [
                'claimed' => true,
                'subsidy' => $subsidy,
                'entitlement' => $entitlement->fresh(),
                'reason' => null,
            ];
        }, 3);
    }

    /**
     * Put an entitlement back - a voided sale, a return, a cancelled pre-order.
     * Idempotent: replaying it must not release twice.
     */
    public function release(Entitlement $entitlement, string $status = Entitlement::RELEASED): bool
    {
        $moved = Entitlement::query()
            ->whereKey($entitlement->getKey())
            ->whereIn('status', [Entitlement::AVAILABLE, Entitlement::RESERVED, Entitlement::CONSUMED])
            ->update([
                'status' => $status,
                'released_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        return $moved === 1;
    }

    /**
     * Today's entitlement row, created once and only once.
     *
     * Kept out of the claim transaction on purpose - see the note at the call
     * site. The read first means the insert is only attempted on the very first
     * scan of the day, so the lock upgrade that causes deadlocks is not even
     * reached on any later scan.
     */
    private function ensureTodaysRow(Employee $employee, SubsidyPlan $plan, CarbonInterface $date): void
    {
        $exists = Entitlement::query()
            ->where('em_employee_id', $employee->id)
            ->where('entitlement_date', $date->toDateString())
            ->exists();

        if ($exists) {
            return;
        }

        $now = Carbon::now();
        Entitlement::query()->insertOrIgnore([
            'em_employee_id' => $employee->id,
            'entitlement_date' => $date->toDateString(),
            'em_company_id' => $employee->em_company_id,
            'em_subsidy_plan_id' => $plan->id,
            'value' => $plan->daily_value,
            'status' => Entitlement::AVAILABLE,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** The employer's share of this basket. */
    public function subsidyFor(SubsidyPlan $plan, float $basketTotal, ?float $dailyValue = null): float
    {
        $value = $dailyValue ?? (float) $plan->daily_value;

        return match ($plan->mode) {
            // employer carries the whole meal
            SubsidyPlan::MODE_FULL => round($basketTotal, 4),
            // employer carries up to the daily value; never more than the basket
            default => round(min($value, $basketTotal), 4),
        };
    }

    /** How the employee's own portion gets settled. */
    private function settleMethodFor(SubsidyPlan $plan, float $employeeOwes): string
    {
        if ($employeeOwes <= 0) {
            return LedgerEntry::SETTLE_NONE;
        }

        return match ($plan->mode) {
            SubsidyPlan::MODE_SALARY => LedgerEntry::SETTLE_SALARY,
            SubsidyPlan::MODE_COMPANY => LedgerEntry::SETTLE_COMPANY,
            // cash vs card is decided by Hyper's own payment screen; the till
            // tells us afterwards. Cash is the assumption until it does.
            default => LedgerEntry::SETTLE_CASH,
        };
    }

    /** Does today count as a working day for this employee's plan or shift? */
    private function qualifiesToday(Employee $employee, CarbonInterface $date): bool
    {
        // Carbon's ISO weekday: 1 = Monday .. 7 = Sunday.
        $weekday = (int) $date->isoWeekday();

        $days = $employee->shift?->qualifyingDays()
            ?: $employee->subsidyPlan?->qualifyingDays()
            ?: [];

        return $days === [] || in_array($weekday, $days, true);
    }
}
