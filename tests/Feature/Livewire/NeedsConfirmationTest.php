<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Concerns\NeedsConfirmation;
use App\Livewire\Experts\ExpertEditor;
use App\Livewire\Projects\EditProject;
use App\Livewire\Projects\ProjectChat;
use App\Livewire\Projects\ProjectNavigation;
use App\Livewire\Settings\Tags;
use App\Livewire\Settings\Users;
use App\Livewire\Statistics\StatisticsPage;
use Livewire\Component;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class NeedsConfirmationTest extends TestCase
{
    public function test_a_confirmed_public_action_runs_with_its_parameters(): void
    {
        Livewire::test(ConfirmingComponent::class)
            ->call('needsConfirmation', 'remove', 7)
            ->call('executeConfirmed')
            ->assertSet('ran', 'remove:7')
            ->assertSet('pendingMethod', null);
    }

    public function test_the_pending_action_cannot_be_set_from_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(ConfirmingComponent::class)->set('pendingMethod', 'secret');
    }

    public function test_the_pending_parameters_cannot_be_set_from_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(ConfirmingComponent::class)->set('pendingParams', [1]);
    }

    /** Every action the app parks for confirmation, so none trips over the guard. */
    public static function appTargets(): array
    {
        return [
            'tags: value' => [Tags::class, 'deleteValue'],
            'tags: category' => [Tags::class, 'deleteCategory'],
            'navigation: group' => [ProjectNavigation::class, 'deleteGroup'],
            'navigation: project' => [ProjectNavigation::class, 'deleteProject'],
            'chat: leave' => [ProjectChat::class, 'leaveProject'],
            'edit project: delete' => [EditProject::class, 'delete'],
            'expert editor: delete' => [ExpertEditor::class, 'delete'],
            'users: delete' => [Users::class, 'delete'],
            'statistics: delete' => [StatisticsPage::class, 'delete'],
        ];
    }

    /** @param  class-string<Component>  $component */
    #[DataProvider('appTargets')]
    public function test_every_confirmed_action_of_the_app_passes_the_guard(string $component, string $method): void
    {
        $guard = new ReflectionMethod($component, 'isConfirmableAction');

        $this->assertTrue($guard->invoke(new $component, $method));
    }

    /** @return array<string, array{string}> */
    public static function forbiddenTargets(): array
    {
        return [
            'private method' => ['secret'],
            'protected method' => ['guarded'],
            'missing method' => ['nothingHere'],
            'livewire base method' => ['skipRender'],
            'the trait itself' => ['needsConfirmation'],
        ];
    }

    #[DataProvider('forbiddenTargets')]
    public function test_only_the_components_own_public_actions_can_be_confirmed(string $method): void
    {
        Livewire::test(ConfirmingComponent::class)
            ->call('needsConfirmation', $method)
            ->call('executeConfirmed')
            ->assertForbidden();
    }
}

class ConfirmingComponent extends Component
{
    use NeedsConfirmation;

    public string $ran = '';

    public function remove(int $id): void
    {
        $this->ran = "remove:{$id}";
    }

    private function secret(): void
    {
        $this->ran = 'secret';
    }

    protected function guarded(): void
    {
        $this->ran = 'guarded';
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
