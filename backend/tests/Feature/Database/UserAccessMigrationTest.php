<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserAccessMigrationTest extends TestCase
{
    private function createLegacyUsersTable(bool $withRole = false): void
    {
        Schema::create('users', function (Blueprint $table) use ($withRole): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            if ($withRole) {
                $table->string('role')->nullable();
            }
        });
    }

    public function test_existing_users_keep_identity_and_password_and_receive_safe_defaults(): void
    {
        $this->createLegacyUsersTable();
        DB::table('users')->insert(['id' => 7, 'name' => 'Legacy User', 'email' => 'legacy@example.com', 'password' => 'legacy-hash']);
        $migration = require database_path('migrations/2026_10_05_120847_add_role_and_is_active_to_users_table.php');

        $migration->up();

        $this->assertDatabaseHas('users', ['id' => 7, 'name' => 'Legacy User', 'email' => 'legacy@example.com', 'password' => 'legacy-hash', 'role' => 'employee', 'is_active' => true]);
        $this->assertDatabaseCount('users', 1);
        $migration->down();
        $this->assertDatabaseHas('users', ['id' => 7, 'role' => 'employee', 'password' => 'legacy-hash']);
    }

    public function test_existing_roles_are_preserved_and_empty_roles_receive_employee_default(): void
    {
        $this->createLegacyUsersTable(true);
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'hash', 'role' => 'admin'],
            ['id' => 2, 'name' => 'Unset', 'email' => 'unset@example.com', 'password' => 'hash', 'role' => null],
            ['id' => 3, 'name' => 'Empty', 'email' => 'empty@example.com', 'password' => 'hash', 'role' => ''],
        ]);
        $migration = require database_path('migrations/2026_10_05_120847_add_role_and_is_active_to_users_table.php');

        $migration->up();
        $migration->up();

        $this->assertDatabaseHas('users', ['id' => 1, 'role' => 'admin', 'is_active' => true]);
        $this->assertDatabaseHas('users', ['id' => 2, 'role' => 'employee', 'is_active' => true]);
        $this->assertDatabaseHas('users', ['id' => 3, 'role' => 'employee', 'is_active' => true]);
        $this->assertDatabaseCount('users', 3);
    }
}
