<?php

namespace Tests\Feature\Policies;

use App\Models\User;
use App\Policies\UserPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    public static function permissionMatrix(): array
    {
        return [
            'active employee' => ['employee', true, false], 'inactive employee' => ['employee', false, false],
            'active technician' => ['technician', true, false], 'inactive technician' => ['technician', false, false],
            'active admin' => ['admin', true, true], 'inactive admin' => ['admin', false, false],
        ];
    }

    #[DataProvider('permissionMatrix')]
    public function test_only_active_admin_can_manage_users(string $role, bool $isActive, bool $allowed): void
    {
        $user = User::factory()->make(['role' => $role, 'is_active' => $isActive]);
        $target = User::factory()->make();
        $policy = new UserPolicy;

        $this->assertSame($allowed, $policy->viewAny($user));
        $this->assertSame($allowed, $policy->create($user));
        $this->assertSame($allowed, $policy->update($user, $target));
    }
}
