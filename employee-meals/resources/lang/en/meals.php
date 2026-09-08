<?php

return [
    'nav' => [
        'section' => 'Employee Meals',
        'employees' => 'Employees',
    ],

    'employees' => [
        'title' => 'Employees',
        'sub' => 'One record per employee. The access card and the fingerprints are two credentials belonging to the same person.',
        'crumb_parent' => 'Employee Meals',
        'new' => 'New employee',
        'edit' => 'Edit employee',
        'created' => ':name has been added.',
        'saved' => ':name has been saved.',
        'removed' => ':name has been removed.',
        'empty' => 'No employees yet.',
        'empty_sub' => 'Add the first employee to start issuing meal entitlements.',
        'no_results' => 'No employee matches that search.',

        'search_placeholder' => 'Name, employee number or card',
        'all_companies' => 'All companies',
        'all_statuses' => 'Active and inactive',
        'only_active' => 'Active only',
        'only_inactive' => 'Inactive only',
        'filter' => 'Search',
        'clear' => 'Clear',

        'columns' => [
            'employee_no' => 'Employee no.',
            'name' => 'Name',
            'department' => 'Department',
            'shift' => 'Shift',
            'card' => 'Access card',
            'plan' => 'Meal plan',
            'status' => 'Status',
            'actions' => '',
        ],

        'fields' => [
            'company' => 'Company',
            'department' => 'Department',
            'shift' => 'Shift',
            'plan' => 'Meal subsidy plan',
            'customer' => 'Linked Hyper customer',
            'employee_no' => 'Employee number',
            'first_name' => 'First name',
            'last_name' => 'Surname',
            'photo' => 'Photograph',
            'card_number' => 'Access card / QR number',
            'is_active' => 'Active',
            'meal_entitled' => 'Entitled to a subsidised meal',
            'notes' => 'Notes',
        ],

        'help' => [
            'customer' => 'Optional. Links this employee to their Hyper customer record so the sale still belongs to a customer and Hyper reporting stays correct. Nothing on the customer is changed.',
            'card_number' => 'Scan the card into this box. A card number can belong to only one employee.',
            'shift' => 'Optional. Sets which weekdays qualify for a subsidised meal.',
            'plan' => 'Decides the daily value and who pays - full subsidy, part contribution, salary account or company account.',
            'meal_entitled' => 'Turn this off for a member of staff who may buy but gets no subsidy.',
        ],

        'cards' => [
            'identity' => 'Identity',
            'placement' => 'Company and placement',
            'credentials' => 'Credentials',
            'entitlement' => 'Meal entitlement',
        ],

        'actions' => [
            'add' => 'Add employee',
            'save' => 'Save',
            'create' => 'Create employee',
            'discard' => 'Discard',
            'edit' => 'Edit',
            'remove' => 'Remove',
            'remove_confirm' => 'Remove :name? Their meal history is kept.',
        ],

        'status' => [
            'active' => 'Active',
            'inactive' => 'Inactive',
            'no_card' => 'No card',
            'no_plan' => 'No plan',
            'not_linked' => 'Not linked',
        ],
    ],
];
