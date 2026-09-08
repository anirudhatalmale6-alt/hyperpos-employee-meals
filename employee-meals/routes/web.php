<?php

use EmployeeMeals\Http\Controllers\EmployeeController;
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
    });
