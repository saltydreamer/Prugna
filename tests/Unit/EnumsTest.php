<?php

namespace Tests\Unit;

use App\Enums\Permission;
use App\Enums\Role;
use PHPUnit\Framework\TestCase;

class EnumsTest extends TestCase
{
    public function test_every_permission_belongs_to_a_group(): void
    {
        $grouped = array_merge(...array_column(Permission::grouped(), 'permissions'));

        $this->assertSame(Permission::values(), array_column($grouped, 'name'));
    }

    public function test_permission_names_follow_the_resource_action_convention(): void
    {
        foreach (Permission::values() as $name) {
            $this->assertMatchesRegularExpression('/^[a-z]+(-[a-z]+)*\.[a-z]+$/', $name);
        }
    }

    public function test_role_labels(): void
    {
        $this->assertSame('Amministratore', Role::labelFor('admin'));
        $this->assertSame('Content Editor', Role::labelFor('content-editor'));
        $this->assertTrue(Role::isSystem('super-admin'));
        $this->assertFalse(Role::isSystem('content-editor'));
    }
}
