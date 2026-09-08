<?php

namespace EmployeeMeals;

use EmployeeMeals\Models\MealCompany;
use EmployeeMeals\Models\SubsidyPlan;
use EmployeeMeals\Support\EntitlementEngine;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Elevate Employee Meal Management.
 *
 * An EXTENSION of Hyper POS. Nothing here edits, replaces or overrides core
 * behaviour: the module adds its own tables, its own screens and its own menu
 * entries, and everything it needs from the POS it gets through the published
 * hooks. That is what lets the vendor keep shipping releases - they have
 * shipped six in two months - without any of this needing to be redone.
 */
class EmployeeMealsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EntitlementEngine::class);
    }

    public function boot(): void
    {
        $this->registerNavigation();
    }

    /**
     * Add our own section to the admin sidebar through the published filter.
     * The core nav config file is never touched.
     */
    private function registerNavigation(): void
    {
        add_filter('admin.nav', function (array $sections, $user = null): array {
            $sections[] = [
                'label' => 'Employee Meals',
                'items' => [
                    [
                        'id' => 'employee-meals-employees',
                        'label' => 'Employees',
                        'icon' => 'customers',
                        'href' => route('employee-meals.employees.index'),
                        'permission' => 'employee-meals.employees.view',
                    ],
                ],
            ];

            return $sections;
        });
    }

    /**
     * Called once, when the plugin is enabled from Settings > Plugins.
     *
     * Seeds Cipla and its daily meal plan so the first screen is not an empty
     * form with no options in it. Idempotent - enabling twice changes nothing,
     * and it never overwrites a value someone has since edited.
     */
    public function onEnable(): void
    {
        if (! Schema::hasTable('em_companies')) {
            return;
        }

        $company = MealCompany::query()->firstOrCreate(
            ['code' => 'CIP'],
            ['name' => 'Cipla', 'settlement' => 'invoice', 'is_active' => true]
        );

        // R38.00 a day, one meal, Monday to Friday, employee pays any
        // difference. Every one of those is a value on a row, not a constant
        // in the code - the client was explicit about that.
        SubsidyPlan::query()->firstOrCreate(
            ['em_company_id' => $company->id, 'name' => 'Cipla daily meal'],
            [
                'mode' => SubsidyPlan::MODE_PART,
                'daily_value' => 38.00,
                'meals_per_day' => 1,
                'qualifying_days' => '1,2,3,4,5',
                'on_exhausted' => 'pay_difference',
                'is_active' => true,
            ]
        );
    }
}
