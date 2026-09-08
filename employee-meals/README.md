# Elevate Employee Meal Management

A Hyper POS **plugin**. It adds employee records, access-card and fingerprint
credentials, meal entitlement with a double-claim block, and a subsidy applied
to the sale at the till.

It is an **extension**, not a fork. No Hyper POS file is edited, replaced or
overridden — everything it needs from the POS it takes through the published
plugin hooks. That is what lets the vendor keep shipping releases without any
of this having to be redone.

## Install

Copy the `employee-meals` folder into the Hyper POS `plugins/` directory, then
enable it from **Settings → Plugins**. Enabling runs the migrations, registers
the permissions and seeds the starting data.

## What it adds

Nine tables, all prefixed `em_`:

| Table | Holds |
| --- | --- |
| `em_companies` | The employer whose staff eat here. Cipla initially. |
| `em_departments` | Departments, for reporting. |
| `em_shifts` | Optional shifts, and which weekdays qualify. |
| `em_subsidy_plans` | The daily value and who pays. |
| `em_employees` | The employee 360 record. |
| `em_fingerprints` | Four fingers per employee, template and image. |
| `em_entitlements` | One row per employee per day. |
| `em_ledger_entries` | Who owed what, for every subsidised sale. |
| `em_settings` | Operator-changeable values. |

### The link to Hyper

`em_employees.customer_id` points at a `customers` row so the sale still
belongs to a Hyper customer and their own reporting stays correct.

It is a plain indexed column, **not** a foreign key, on purpose: a Hyper
upgrade, restore or table rebuild must never fail because of a constraint this
plugin added. Nothing on the customer record is written to.

## The four settlement modes

Set on the subsidy plan, as data. The daily value is never hard-coded.

| Mode | Employer pays | Employee pays |
| --- | --- | --- |
| `full` | the whole meal | nothing |
| `part` | up to the daily value | the remainder, at the till |
| `salary` | up to the daily value | the remainder, cleared by payroll |
| `company` | up to the daily value | the remainder, charged to the company account |

`salary` and `company` ledger lines stay open (`is_settled = 0`) until payroll
runs or the company settles. That open set **is** the month-end deduction list.

## Why a ledger and not just a discount

A discount reduces a total but never records *who owed what*, so on its own it
cannot produce a month-end employer/employee split or a payroll deduction. The
discount is the mechanism that puts the employer's share on the sale; the
ledger is the record of truth. Every reporting figure the client asked for is a
query over `em_ledger_entries`.

## The double-claim block

`EntitlementEngine::claim()` is the only way a subsidy is taken. Three things
make it safe, and all three were arrived at by measurement, not by taste:

1. **A unique key** on `(em_employee_id, entitlement_date)`. The database
   itself refuses a second row for the day.
2. **A single-statement claim** — `UPDATE … SET status = 'consumed' WHERE …
   AND status IN ('available','reserved')`. An affected-row count of zero *is*
   the refusal, so it cannot be raced by a double-click or a network retry.
3. **The row is created in its own committed statement, outside the claim
   transaction.** Ignoring a duplicate insert takes a shared lock; the update
   then wants an exclusive one, and a dozen tills all upgrading S→X inside
   their own transactions deadlock each other.

Two related traps, both measured on 12 concurrent tills:

- `firstOrCreate` is read-then-write. Two tills both read nothing, both insert,
  and the loser gets a raw duplicate-key exception — at a till that is an error
  page, not "already collected". Use `insertOrIgnore`.
- `whereDate('entitlement_date', …)` emits `date(entitlement_date) = ?`, which
  **cannot use the unique index**. MySQL falls back to a scan holding gap
  locks and every run deadlocked. Compare the column directly.

## Tests

```bash
# 12 tills scanning one card at the same microsecond.
# Passes only if exactly one is allowed and one ledger line exists.
plugins/employee-meals/tests/run-double-claim.sh /path/to/mysql.sock 12 <employee-id>
```

Typical output:

```
till 7   ALLOWED   subsidy R  38.00 applied        (30.4 ms)
till 12  REFUSED   already_collected      09:01   (32.5 ms)
…
entitlement rows today : 1   (must be 1)
subsidies allowed      : 1   (must be 1)
ledger lines           : 1   (must be 1)
company paid           : R 38.0000
employee paid          : R 25.0000
PASS - one meal, one subsidy, one ledger line.
```

## Status

| Phase 1 piece | State |
| --- | --- |
| 1. Employee 360 record + access card | **done** |
| 2. Card and fingerprint enrolment | to do — ports from an existing DigitalPersona build |
| 3. Meal entitlement engine | **done**, including the double-claim block |
| 4. Employee pre-ordering | to do |
| 5. POS identification + subsidy at checkout | to do |
| 6. Kitchen and subsidy reporting | to do |

## Hooks this plugin uses

`admin.nav` today; `sale.fillable`, `sale.before_complete`,
`sale.after_complete`, `sale.after_void`, `sale.after_return`,
`ui.slot.cashier.body.end` and `receipt.html` as the remaining pieces land.
