<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Locked;
use Livewire\Component;
use ReflectionMethod;

/**
 * Parks an action until the shared confirm modal calls executeConfirmed().
 *
 * The pending action is locked against client writes, and executeConfirmed()
 * runs only a public method the component declares itself: a forged request
 * could otherwise reach a private helper or a Livewire base method from inside
 * the class.
 */
trait NeedsConfirmation
{
    /** Optional per-component overrides; null falls back to the translated defaults. */
    public ?string $confirmTitle = null;

    public ?string $confirmMessage = null;

    /** Name of the red confirm button; null keeps the shared "Confirm". */
    public ?string $confirmLabel = null;

    #[Locked]
    public ?string $pendingMethod = null;

    #[Locked]
    public array $pendingParams = [];

    public function needsConfirmation(string $method, mixed ...$params): void
    {
        $this->pendingMethod = $method;
        $this->pendingParams = array_values($params);

        $this->dispatch('open-confirm',
            componentId: $this->getId(),
            title: $this->confirmTitle ?? __('common.confirm_dialog.title'),
            message: $this->confirmMessage ?? __('common.confirm_dialog.message'),
            label: $this->confirmLabel ?? __('common.actions.confirm'),
        );
    }

    public function executeConfirmed(): void
    {
        if (! $this->pendingMethod) {
            return;
        }

        $method = $this->pendingMethod;
        $params = $this->pendingParams;
        $this->reset(['pendingMethod', 'pendingParams']);

        abort_unless($this->isConfirmableAction($method), 403);

        $this->$method(...$params);
    }

    /** A public method of the component itself, not one a trait or Livewire brought in. */
    private function isConfirmableAction(string $method): bool
    {
        if (! method_exists($this, $method)) {
            return false;
        }

        $reflection = new ReflectionMethod($this, $method);
        if (! $reflection->isPublic() || $reflection->isStatic()) {
            return false;
        }

        $declaredByTrait = collect(class_uses_recursive($this))
            ->contains(fn (string $trait) => method_exists($trait, $method));

        return ! $declaredByTrait && is_subclass_of($reflection->getDeclaringClass()->getName(), Component::class);
    }
}
