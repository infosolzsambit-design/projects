<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('admin.email');
        $username = (string) config('admin.username');
        $password = (string) config('admin.password');
        $name = (string) config('admin.name');

        if ($email === '' || $username === '' || $password === '') {
            $this->command?->warn(
                'ADMIN_EMAIL / ADMIN_USERNAME / ADMIN_PASSWORD are not set in .env — skipping Super Admin user seeding.'
            );

            return;
        }

        $user = User::withTrashed()
            ->where('email', $email)
            ->orWhere('username', $username)
            ->first();

        if (! $user) {
            $user = User::create([
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'is_active' => true,
            ]);
        } elseif ($user->trashed()) {
            $user->restore();
        }

        // Only the *first* configured super-admin id — see RoleSeeder.php's
        // own comment on config('roles.super_admin_id') being a list; this
        // seeded account only ever gets the one "Super Admin" role it
        // actually creates, not any other role also listed there.
        $user->syncRoles([(int) ((array) config('roles.super_admin_id'))[0]]);
    }
}
