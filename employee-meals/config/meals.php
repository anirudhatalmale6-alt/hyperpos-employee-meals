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
     * Where the numpy and opencv packages live, if they are not installed
     * system wide.
     *
     * ⚠️ THIS IS THE SETTING THAT MATTERED. On shared hosting you rarely get
     * to install into the system Python, so the packages go into a folder in
     * the home directory with `pip install --target`, and Python has to be
     * told where to look. Our other install does exactly this - the binary
     * was always present, only PYTHONPATH was missing - and without it the
     * check reports "no Python found" when Python is right there.
     *
     * Example: /home/u123456/.python-packages
     */
    'python_path' => env('EMPLOYEE_MEALS_PYTHONPATH'),

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
