<?php

namespace App\Livewire\Concerns;

trait NeedsConfirmation
{
    /** Optional per-component overrides; null falls back to the translated defaults. */
    public ?string $confirmTitle   = null;
    public ?string $confirmMessage = null;
    public ?string $pendingMethod = null;
    public array $pendingParams   = [];

    public function needsConfirmation(string $method, mixed ...$params): void
    {
        $this->pendingMethod = $method;
        $this->pendingParams = array_values($params);

        $this->dispatch('open-confirm',
            componentId: $this->getId(),
            title:       $this->confirmTitle ?? __('common.confirm_dialog.title'),
            message:     $this->confirmMessage ?? __('common.confirm_dialog.message'),
        );
    }

    public function executeConfirmed(): void
    {
        if ($this->pendingMethod) {
            $method = $this->pendingMethod;
            $params = $this->pendingParams;
            $this->reset(['pendingMethod', 'pendingParams']);
            $this->$method(...$params);
        }
    }
}
