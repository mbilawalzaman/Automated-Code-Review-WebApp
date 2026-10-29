<?php

namespace Tests\Unit\Rules;

use App\Models\Permission;
use App\Rules\ManageRequiresViewPermission;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ManageRequiresViewPermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manage_without_view_fails_validation(): void
    {
        $manageUsers = Permission::query()->where('name', 'users.manage')->firstOrFail();

        $validator = Validator::make(
            ['permissions' => [$manageUsers->id]],
            ['permissions' => [new ManageRequiresViewPermission()]]
        );

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('View Users', $validator->errors()->first('permissions'));
    }

    public function test_manage_with_view_passes_validation(): void
    {
        $manageUsers = Permission::query()->where('name', 'users.manage')->firstOrFail();
        $viewUsers = Permission::query()->where('name', 'users.view')->firstOrFail();

        $validator = Validator::make(
            ['permissions' => [$manageUsers->id, $viewUsers->id]],
            ['permissions' => [new ManageRequiresViewPermission()]]
        );

        $this->assertFalse($validator->fails());
    }

    public function test_manage_without_view_sibling_is_allowed(): void
    {
        $integrationsManage = Permission::query()->where('name', 'integrations.manage')->firstOrFail();

        $validator = Validator::make(
            ['permissions' => [$integrationsManage->id]],
            ['permissions' => [new ManageRequiresViewPermission()]]
        );

        $this->assertFalse($validator->fails());
    }
}
