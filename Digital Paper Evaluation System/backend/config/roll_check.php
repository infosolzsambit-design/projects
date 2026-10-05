<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Student ID / Roll Number Check
    |--------------------------------------------------------------------------
    |
    | Reading the handwritten STUDENT'S ID NO. off each answer sheet's cover
    | page runs ocr/read_student_id.py (Python + OpenCV + a small digit
    | model — see ocr/README.md for server setup) directly, in batches,
    | driven by the Generate Marksheet page's progress bar. No queue.
    |
    */

    // Resolved relative to wherever the project lives — never a hardcoded
    // path. Order: ROLL_CHECK_PYTHON in .env, else the ocr/.venv built on
    // this machine by `bash ocr/setup.sh`, else the system python3.
    'python' => env('ROLL_CHECK_PYTHON')
        ?: (is_file(base_path('ocr/.venv/bin/python')) ? base_path('ocr/.venv/bin/python') : 'python3'),

    'script' => base_path('ocr/read_student_id.py'),

    // Per sheet; a batch gets this times its size.
    'timeout_seconds' => (int) env('ROLL_CHECK_TIMEOUT', 20),

];
