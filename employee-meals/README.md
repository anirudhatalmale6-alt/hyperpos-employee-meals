# Elevate Employee Meal Management

A Hyper POS **plugin**. It adds employee records, access-card and fingerprint
credentials, meal entitlement with a double-claim block, and a subsidy applied
to the sale at the till.

It is an **extension**, not a fork. No Hyper POS file is edited, replaced or
overridden — everything it needs from the POS it takes through the published
plugin hooks. That is what lets the vendor keep shipping releases without any
of this having to be redone.

## Install

Hyper installs it itself — no file manager, no SSH.

1. Log in as the **owner / super admin**. `PluginController::ownerOnly()`
   aborts 403 for anyone else, on every action including the listing.
2. **Settings → Plugins**: choose the zip, **type your own login password**,
   upload.
3. Click **Enable** and **type your password again**.
4. *Employee Meals* appears at the foot of the sidebar.

Enabling runs the migrations, registers the permissions and seeds Cipla plus
the R38 plan.

⚠️ **Both the upload and the enable require `current_password`** — the
operator's own login password, validated by Laravel's `current_password` rule.
Miss it and the request comes back 422 with the page apparently unchanged,
which reads as "the upload did nothing". Uninstall additionally wants
`confirm_slug` typed out as `employee-meals`.

⚠️ **Never upload a replacement over an enabled plugin — disable it first.**
`InstallPlugin` moves the existing directory aside before moving the new one
in; when that sequence was interrupted during testing the plugin folder was
left **missing** and had to be restored from the zip. Uninstalling does NOT
drop the `em_` tables, so employee and ledger data survives.

Packaging: zip the `employee-meals` folder so `plugin.json` sits either at the
archive root or inside exactly one top-level folder — `packageRoot()` rejects
anything else. Limit 50 MB.

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
| 2. Card and fingerprint enrolment | **done** — screen, capture, storage and 1-to-many identification |
| 3. Meal entitlement engine | **done**, including the double-claim block |
| 4. Employee pre-ordering | to do |
| 5. POS identification + subsidy at checkout | to do |
| 6. Kitchen and subsidy reporting | to do |

## Fingerprints

The DigitalPersona JavaScript SDK only **captures**. Comparing two prints is
FingerJet, a Windows native library from the paid SDK, so on a Linux host the
comparison is ours: `resources/python/fpmatch.py` does segment → orientation
field → Gabor bank → binarise → thin → crossing-number minutiae → Hough vote on
the transform → count agreeing pairs.

Requirements, and the screen reports each **separately** so a failure names
itself:

| Needs | Where |
| --- | --- |
| HID Authentication Device Client | the till PC (Windows) — a browser cannot see a USB reader without it |
| Python 3 with NumPy and OpenCV | the web server — enrolment works without it, identification does not |

Download the client from <https://crossmatch.hid.gl/lite-client/>. HID renamed
it, so searching for "Lite Client" finds nothing.

### Measured, not assumed

On four real FVC prints (two impressions each of two fingers) plus synthetic
re-presses — shifted, rotated, partly cropped, noised:

```
different fingers (impostor) : 0, 0, 2, 3
same finger, good overlap    : 16, 20, 21, 47, 51, 57, 63, 74
same finger, poor overlap    : 2, 10
```

`ACCEPT = 15` — five times the worst impostor, below all but the poor-overlap
genuine pairs. The bias is deliberate: a false reject costs one more press, a
false accept gives one employee another's subsidy and corrupts the billing.

⚠️ Two fingers is a small sample. Re-run the measurement on the real reader with
real staff before trusting it in production.

Three traps, all of which fail late and confusingly:

- `SampleFormat.PngImage` is **5**, not 4 — the enum skips 4 (Raw 1,
  Intermediate 2, Compressed 3, PngImage 5).
- Samples arrive **base64url** (`-` `_`, no padding). `base64_decode()` does not
  error on those; it returns corrupt bytes and fails much later.
- The UMD bundles publish under **`dp.devices`**, not a global called
  `Fingerprint`, and `event.samples` is **already parsed** — parsing it again
  throws and the touch is silently lost.

## Theme notes

Two classes in this theme do not do what their names suggest, and both were
found the hard way:

- `field-error` is `display:none` **unconditionally**. It exists only to turn a
  sibling input red through `:has()`. Text placed in it is invisible.
- `alert` is a three-column grid (`20px 1fr auto`). Content dropped straight
  into it lands in the 20px icon column and wraps one word per line. Always go
  through the `<x-alert type="...">` component.

## Hooks this plugin uses

`admin.nav` today; `sale.fillable`, `sale.before_complete`,
`sale.after_complete`, `sale.after_void`, `sale.after_return`,
`ui.slot.cashier.body.end` and `receipt.html` as the remaining pieces land.
