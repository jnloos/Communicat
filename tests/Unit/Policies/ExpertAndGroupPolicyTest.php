<?php

namespace Tests\Unit\Policies;

use App\Models\Expert;
use App\Models\ProjectGroup;
use App\Models\User;
use App\Policies\ExpertPolicy;
use App\Policies\ProjectGroupPolicy;
use Tests\TestCase;

class ExpertAndGroupPolicyTest extends TestCase
{
    public function test_everyone_reads_experts_and_only_admins_write_them(): void
    {
        $policy = new ExpertPolicy;
        $expert = new Expert;

        foreach ([false, true] as $admin) {
            $user = (new User)->forceFill(['id' => 1, 'is_admin' => $admin]);

            $this->assertTrue($policy->viewAny($user));
            $this->assertTrue($policy->view($user, $expert));
            $this->assertSame($admin, $policy->create($user));
            $this->assertSame($admin, $policy->update($user, $expert));
            $this->assertSame($admin, $policy->delete($user, $expert));
        }
    }

    public function test_a_foreign_group_is_not_found_rather_than_forbidden(): void
    {
        $policy = new ProjectGroupPolicy;
        $group = (new ProjectGroup)->forceFill(['user_id' => 1]);
        $owner = (new User)->forceFill(['id' => 1]);
        $stranger = (new User)->forceFill(['id' => 2, 'is_admin' => true]);

        $this->assertTrue($policy->update($owner, $group)->allowed());
        $this->assertTrue($policy->delete($owner, $group)->allowed());
        $this->assertSame(404, $policy->update($stranger, $group)->status());
        $this->assertSame(404, $policy->delete($stranger, $group)->status());
    }
}
