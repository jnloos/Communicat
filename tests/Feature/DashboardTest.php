<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_authenticated_users_are_sent_to_project_creation(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/')->assertRedirect(route('project.new'));
    }
}
