<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Well-Known Role IDs
    |--------------------------------------------------------------------------
    |
    | Role checks throughout this app use IDs, never role names — seeded
    | with this fixed, explicit ID by database/seeders/RoleSeeder.php so it
    | never depends on insertion order or auto-increment guesswork.
    |
    */

    'super_admin_id' => (int) env('SUPER_ADMIN_ROLE_ID', 1),

    'teacher_id' => (int) env('TEACHER_ROLE_ID', 2),

];
