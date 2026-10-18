<?php

namespace Tests\Feature\Services;

use App\Models\User;
use App\Services\AdminUserService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUserServiceTest extends TestCase
{
    use RefreshDatabase;

    public static function revokedAccess(): array
    {
        return ['demoted' => [['role' => 'employee']], 'disabled' => [['is_active' => false]]];
    }

    #[DataProvider('revokedAccess')]
    public function test_account_creation_rechecks_current_admin_access_before_writing(array $change): void
    {
        $admin = User::factory()->admin()->create();
        User::query()->whereKey($admin->id)->update($change);

        try {
            $this->app->make(AdminUserService::class)->create($admin, [
                'name' => 'Denied user', 'email' => 'denied@example.com', 'password' => 'SecurePassword123!', 'role' => 'employee',
            ]);
            $this->fail('A revoked administrator must not create accounts.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseMissing('users', ['email' => 'denied@example.com']);
        }
    }

    #[DataProvider('revokedAccess')]
    public function test_account_update_rechecks_current_admin_access_before_writing(array $change): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        User::query()->whereKey($admin->id)->update($change);

        try {
            $this->app->make(AdminUserService::class)->update($admin, $target, ['role' => 'admin']);
            $this->fail('A revoked administrator must not update accounts.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'employee']);
        }
    }
}
