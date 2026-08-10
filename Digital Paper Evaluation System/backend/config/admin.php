<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Initial Super Admin
    |--------------------------------------------------------------------------
    |
    | Read once by database/seeders/AdminUserSeeder.php. No password is
    | hard-coded anywhere in source — if these are left empty, that seeder
    | skips itself rather than creating an account with a guessable password.
    |
    */

    'name' => env('ADMIN_NAME', 'Super Admin'),
    'username' => env('ADMIN_USERNAME'),
    'email' => env('ADMIN_EMAIL'),
    'password' => env('ADMIN_PASSWORD'),

];
