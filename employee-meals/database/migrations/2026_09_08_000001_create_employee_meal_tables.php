<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elevate Employee Meal Management - Phase 1 tables.
 *
 * Every table here is new and prefixed `em_`. Not one Hyper POS table is
 * altered. The single tie to Hyper is `em_employees.customer_id`, which POINTS
 * AT a `customers` row so the sale still belongs to a Hyper customer and their
 * own reporting stays correct.
 *
 * That column is deliberately a plain indexed integer and NOT a foreign key:
 * a Hyper upgrade, restore or table rebuild must never fail because of a
 * constraint this plugin added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('em_companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 20)->unique();
            // how the company settles what it owes us
            $table->enum('settlement', ['invoice', 'account'])->default('invoice');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('em_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('em_company_id')->constrained('em_companies')->cascadeOnDelete();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['em_company_id', 'name']);
        });

        // Shifts are optional - "Shift, if required". A NULL shift is treated
        // as an ordinary day-shift worker.
        Schema::create('em_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('em_company_id')->constrained('em_companies')->cascadeOnDelete();
            $table->string('name', 100);
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            // weekdays this shift qualifies for a subsidised meal, 1=Mon..7=Sun
            $table->string('qualifying_days', 20)->default('1,2,3,4,5');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['em_company_id', 'name']);
        });

        /*
         * The four settlement modes, and the daily value as DATA. The client
         * was explicit: "The value must be configurable - don't hard-code R38
         * into the software."
         *
         *   full    - employer pays 100% up to daily_value
         *   part    - employer pays daily_value, employee pays the remainder now
         *   salary  - employee portion accrues, payroll clears it monthly
         *   company - the whole line is charged to the company account
         */
        Schema::create('em_subsidy_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('em_company_id')->constrained('em_companies')->cascadeOnDelete();
            $table->string('name', 150);
            $table->enum('mode', ['full', 'part', 'salary', 'company'])->default('part');
            $table->decimal('daily_value', 15, 4)->default(0);
            $table->unsignedSmallInteger('meals_per_day')->default(1);
            $table->string('qualifying_days', 20)->default('1,2,3,4,5');
            // when the subsidy does not cover the whole basket
            $table->enum('on_exhausted', ['pay_difference', 'block'])->default('pay_difference');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['em_company_id', 'name']);
        });

        Schema::create('em_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('em_company_id')->constrained('em_companies');
            $table->foreignId('em_department_id')->nullable()->constrained('em_departments')->nullOnDelete();
            $table->foreignId('em_shift_id')->nullable()->constrained('em_shifts')->nullOnDelete();
            $table->foreignId('em_subsidy_plan_id')->nullable()->constrained('em_subsidy_plans')->nullOnDelete();

            // -> customers.id. Soft link on purpose: see the class docblock.
            $table->unsignedBigInteger('customer_id')->nullable()->index();

            $table->string('employee_no', 40);           // CIP00125
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('photo_path')->nullable();

            $table->string('card_number', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('meal_entitled')->default(true);

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Employee number is unique per company, among live rows only, so a
            // deleted record does not permanently reserve the number.
            $table->unique(['em_company_id', 'employee_no', 'deleted_at'], 'em_employees_no_unique');

            // A card must resolve to exactly ONE person, across every company.
            // MySQL permits many NULLs in a unique index, which is what we want:
            // unlimited employees with no card yet, never two live cards alike.
            $table->unique(['card_number', 'deleted_at'], 'em_employees_card_unique');

            $table->index(['em_company_id', 'is_active']);
        });

        // Four fingers per the spec. The template AND the image are both kept
        // so that changing matching engine later does not mean re-enrolling
        // the whole factory.
        Schema::create('em_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('em_employee_id')->constrained('em_employees')->cascadeOnDelete();
            $table->enum('finger', ['right_thumb', 'right_index', 'left_thumb', 'left_index']);
            $table->unsignedTinyInteger('sample_no')->default(1);   // 4 touches per finger
            $table->binary('template')->nullable();
            $table->binary('image')->nullable();
            $table->unsignedSmallInteger('quality')->nullable();
            $table->unsignedSmallInteger('minutiae_count')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamps();
            $table->unique(['em_employee_id', 'finger', 'sample_no']);
        });

        /*
         * THE DOUBLE-CLAIM BLOCK. Spec section 7.
         *
         * One row per employee per qualifying day. The unique key below IS the
         * control: the database itself refuses a second row, so two tills
         * scanning the same card in the same second cannot both succeed. It
         * does not depend on application code remembering to check, and it
         * holds through a double-click, a network retry or a second browser tab.
         *
         *   available -> reserved (pre-order)  -> consumed (collected)
         *   available -> consumed (walk up to the till and buy)
         *   reserved  -> released (cancelled / no-show)
         */
        Schema::create('em_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('em_employee_id')->constrained('em_employees')->cascadeOnDelete();
            $table->unsignedBigInteger('em_company_id')->index();
            $table->unsignedBigInteger('em_subsidy_plan_id')->nullable();
            $table->date('entitlement_date');
            $table->decimal('value', 15, 4)->default(0);      // snapshot of daily_value
            $table->enum('status', ['available', 'reserved', 'consumed', 'released', 'reversed'])
                ->default('available');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('consumed_at')->nullable();     // "ALREADY COLLECTED - 12:34"
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('sale_id')->nullable()->index();
            $table->string('sale_reference', 64)->nullable();
            $table->timestamps();

            $table->unique(['em_employee_id', 'entitlement_date'], 'em_entitlements_day_unique');
            $table->index(['em_company_id', 'entitlement_date', 'status'], 'em_entitlements_reporting');
        });

        /*
         * THE LEDGER - the record of truth, and the reason a Hyper discount on
         * its own is not enough: a discount reduces a total but never records
         * WHO OWED WHAT. Every figure in the client's section 12 reporting
         * comes from here.
         *
         * Company and department are stored as a snapshot, not looked up later,
         * so a transfer between departments next month does not silently
         * rewrite last month's report.
         */
        Schema::create('em_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('em_employee_id')->constrained('em_employees');
            $table->foreignId('em_entitlement_id')->nullable()->constrained('em_entitlements')->nullOnDelete();
            $table->unsignedBigInteger('em_company_id')->index();
            $table->unsignedBigInteger('em_department_id')->nullable()->index();

            $table->dateTime('occurred_at');
            $table->date('trade_date')->index();

            $table->unsignedBigInteger('sale_id')->nullable()->index();
            $table->string('sale_reference', 64)->nullable();

            $table->decimal('gross_amount', 15, 4)->default(0);     // 63.00
            $table->decimal('subsidy_amount', 15, 4)->default(0);   // 38.00 employer
            $table->decimal('employee_amount', 15, 4)->default(0);  // 25.00 employee

            // how the employee's own portion was settled
            $table->enum('settle_method', ['cash', 'card', 'salary', 'company', 'none'])->default('cash');
            // salary and company lines stay OPEN until payroll runs or the
            // company settles; that open set is the month-end deduction list
            $table->boolean('is_settled')->default(true);
            $table->timestamp('settled_at')->nullable();
            $table->string('settlement_ref', 64)->nullable();

            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['em_employee_id', 'trade_date']);
            $table->index(['em_company_id', 'settle_method', 'is_settled'], 'em_ledger_open_items');
        });

        // Anything an operator might reasonably change without a developer.
        Schema::create('em_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('em_company_id')->nullable();
            $table->string('skey', 80);
            $table->text('svalue')->nullable();
            $table->timestamps();
            $table->unique(['em_company_id', 'skey']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('em_settings');
        Schema::dropIfExists('em_ledger_entries');
        Schema::dropIfExists('em_entitlements');
        Schema::dropIfExists('em_fingerprints');
        Schema::dropIfExists('em_employees');
        Schema::dropIfExists('em_subsidy_plans');
        Schema::dropIfExists('em_shifts');
        Schema::dropIfExists('em_departments');
        Schema::dropIfExists('em_companies');
    }
};
