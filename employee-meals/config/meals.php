<?php

return [
    /*
     * Where to find Python for fingerprint matching.
     *
     * The comparison needs Python 3 with NumPy and OpenCV. Shared hosting very
     * often has it, but not on the PATH and not called "python3" - cPanel and
     * CloudLinux boxes typically hide it under /opt/alt/pythonXXX/bin/. If the
     * enrolment screen says no matching engine was found, set the full path
     * here (or in .env as EMPLOYEE_MEALS_PYTHON) and check the screen again.
     *
     * Leave it null to try the usual places.
     */
    'python_binary' => env('EMPLOYEE_MEALS_PYTHON'),

    /*
     * Extra places to look, tried in order after the configured binary.
     */
    'python_candidates' => [
        'python3',
        '/usr/bin/python3',
        '/usr/local/bin/python3',
        '/opt/alt/python313/bin/python3.13',
        '/opt/alt/python312/bin/python3.12',
        '/opt/alt/python311/bin/python3.11',
        '/opt/alt/python310/bin/python3.10',
        '/opt/alt/python39/bin/python3.9',
        '/opt/cpanel/ea-python311/bin/python3',
        '/usr/local/cpanel/3rdparty/bin/python3',
    ],
];
