<?php

namespace Tests\Feature\Layout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarResizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sidebar_renders_an_accessible_resize_handle(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('experts'))
            ->assertOk()
            ->assertSee('data-sidebar-resize-handle', false)
            ->assertSee('role="separator"', false)
            ->assertSee('aria-orientation="vertical"', false)
            ->assertSee('tabindex="0"', false)
            ->assertSee('title="'.__('navigation.resize_hint').'"', false)
            ->assertSee('w-4 ', false)
            ->assertSee('start-[calc(var(--sidebar-current)-0.5rem)]', false)
            ->assertSee('aria-label="'.__('navigation.resize_sidebar').'"', false)
            ->assertSee(':aria-valuenow="now"', false)
            ->assertSee(':aria-valuemin="min"', false)
            ->assertSee(':aria-valuemax="max"', false);
    }

    public function test_the_stored_width_is_read_before_first_paint_under_the_configured_key(): void
    {
        $this->actingAs(User::factory()->create());

        $key = config('ui.sidebar_width_key');

        $html = $this->get(route('experts'))->getContent();

        $this->assertStringContainsString('localStorage.getItem(', $html);
        $this->assertStringContainsString($key, substr($html, 0, strpos($html, '<body')));
        $this->assertLessThan(strpos($html, '<body'), strpos($html, '--sidebar-width'));
        $this->assertStringContainsString('sidebarResize(', $html);
    }

    public function test_the_labels_exist_in_both_languages(): void
    {
        foreach (['de', 'en'] as $locale) {
            $this->assertNotSame(
                'navigation.resize_sidebar',
                __('navigation.resize_sidebar', [], $locale),
            );
            $this->assertNotSame(
                'navigation.resize_hint',
                __('navigation.resize_hint', [], $locale),
            );
        }
    }
}
