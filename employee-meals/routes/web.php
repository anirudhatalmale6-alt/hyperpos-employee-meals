<?php

use EmployeeMeals\Http\Controllers\EmployeeController;
use EmployeeMeals\Http\Controllers\FingerprintController;
use Illuminate\Support\Facades\Route;

/*
 * Plugin routes. The loader already wraps this file in the `web` group, so we
 * only add the same guards Hyper puts on its own admin screens: installed,
 * authenticated, a store chosen, and the user's locale applied.
 */
Route::middleware(['ensure.installed', 'auth', 'store.selected', 'set.locale'])
    ->prefix('admin/employee-meals')
    ->name('employee-meals.')
    ->group(function () {
        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('employees/create', [EmployeeController::class, 'create'])->name('employees.create');
        Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::get('employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
        Route::patch('employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');

        // Card lookup used by the enrolment screen and, later, by the till.
        Route::get('lookup/card', [EmployeeController::class, 'lookupByCard'])
            ->middleware('throttle:240,1')
            ->name('lookup.card');

        // ── Card and fingerprint enrolment ──────────────────────────────
        Route::get('enrol', [FingerprintController::class, 'index'])->name('enrol.index');

        // Plain, framework-free reader test - see the controller docblock.
        Route::get('enrol/selftest', [FingerprintController::class, 'selftest'])->name('enrol.selftest');

        // ⚠️ No .js on these paths. The Laravel front controller's rewrite
        // commonly excludes anything ending in .js, so a registered route with
        // that extension still 404s. Name them plainly and map to the file in
        // the controller.
        Route::get('sdk/{name}', [FingerprintController::class, 'sdk'])
            ->whereIn('name', ['websdk', 'core', 'devices'])
            ->name('sdk');

        Route::post('enrol/{employee}/card', [FingerprintController::class, 'card'])
            ->name('enrol.card');
        Route::post('enrol/{employee}/finger', [FingerprintController::class, 'store'])
            ->middleware('throttle:120,1')
            ->name('enrol.store');
        Route::delete('enrol/{employee}/finger/{finger}', [FingerprintController::class, 'destroy'])
            ->name('enrol.destroy');
        Route::post('enrol/test', [FingerprintController::class, 'test'])
            ->middleware('throttle:120,1')
            ->name('enrol.test');
    });
