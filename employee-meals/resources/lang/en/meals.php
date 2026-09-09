<?php

return [
    'nav' => [
        'section' => 'Employee Meals',
        'employees' => 'Employees',
        'enrol' => 'Card & Fingerprint',
    ],

    'fingerprints' => [
        'title' => 'Card and fingerprint enrolment',
        'sub' => 'Find the employee, then enrol their credentials. The card and the fingers all identify the same person.',

        'find' => 'Find employee',
        'find_placeholder' => 'Name, employee number or card',
        'search' => 'Search',
        'no_matches' => 'No employee matches that search.',
        'choose' => 'Search for an employee, then press Select to load them for enrolment.',
        'change' => 'Change employee',
        'select' => 'Select',
        'selected' => 'Selected',
        'has_card' => 'Card :card',
        'fingers_enrolled' => ':count of 4 fingers enrolled',

        'card' => [
            'title' => 'Access card',
            'label' => 'Scan card',
            'help' => 'Scan the card into this box - the reader types it like a keyboard. A card can belong to only one employee.',
            'save' => 'Save card',
            'saved' => 'Card saved.',
            'cleared' => 'Card removed.',
        ],

        'reader' => [
            'title' => 'Fingerprint reader',
            'libraries' => 'Reader libraries',
            'agent' => 'Reader service',
            'devices' => 'Readers found',
            'matcher' => 'Matching engine',
            'checking' => 'Checking…',
            'loaded' => 'Loaded',
            'not_loaded' => 'Not loaded',
            'answering' => 'Answering',
            'not_answering' => 'Not answering',
            'none' => 'None',
            'install_title' => 'The reader service is not running on this PC.',
            'install_body' => 'A browser cannot see a USB reader on its own. Install the HID Authentication Device Client on this Windows PC, then reload this page. Nothing on the website can replace it.',
            'install_link' => 'https://crossmatch.hid.gl/lite-client/',
        ],

        'fingers' => [
            'title' => 'Fingers',
            'right_thumb' => 'Right thumb',
            'right_index' => 'Right index',
            'left_thumb' => 'Left thumb',
            'left_index' => 'Left index',
            'enrolled' => 'Enrolled',
            'not_enrolled' => 'Not enrolled',
            'samples' => ':count of :target touches',
            'capture' => 'Capture',
            'capturing' => 'Place the finger on the reader…',
            'clear' => 'Clear',
            'target_help' => 'Four touches per finger. Each probe is matched against every stored touch and the best one counts, so one crooked press does no harm.',
        ],

        'test' => [
            'title' => 'Test',
            'help' => 'Put any enrolled finger on the reader. Nobody types a name first - this is the same search the till will do.',
            'start' => 'Scan to identify',
            'accepted' => 'Identified',
            'uncertain' => 'Not sure - ask for another press',
            'rejected' => 'No match',
            'score' => 'Score :score, threshold :threshold, runner-up :runner',
            'runner_help' => 'The runner-up is the score of the next closest person. A high score means little if second place is right behind it.',
        ],

        'errors' => [
            'not_an_image' => 'That capture did not arrive as a readable image. Try again.',
            'too_faint' => 'That press was too faint to store - only :count usable points were found. Press a little firmer and try again.',
            'no_employee' => 'Choose an employee first.',
        ],
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
        'errors_title' => 'That could not be saved.',

        'photo' => [
            'none' => 'No photo',
            'use_camera' => 'Use camera',
            'take' => 'Take photo',
            'cancel' => 'Cancel',
            'remove' => 'Remove',
            'taken' => 'Photo taken. Save the employee to keep it.',
            'no_camera' => 'The camera could not be opened. Check the browser has permission.',
            'needs_https' => 'The camera only works on a secure (https) address. Choose a file instead.',
        ],

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
