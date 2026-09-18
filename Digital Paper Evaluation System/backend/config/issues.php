<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Well-Known Issue Master IDs
    |--------------------------------------------------------------------------
    |
    | EvaluatePaperView.vue's "Problem" modal defaults its (currently
    | locked, non-editable) issue dropdown to Printing Issue — resolved by
    | this ID, never by matching the "Printing Issue" name string. Seeded
    | with this fixed, explicit ID by database/seeders/IssueMasterSeeder.php
    | so it never depends on insertion order or auto-increment guesswork,
    | same convention as roles.super_admin_id.
    |
    */

    'printing_issue_id' => (int) env('PRINTING_ISSUE_ID', 1),

    'timing_issue_id' => (int) env('TIMING_ISSUE_ID', 2),

];
