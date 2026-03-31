<?php

namespace App\Livewire\Concerns;

trait NeedsConfirmation
{
    public string $confirmTitle   = 'Are you sure?';
    public string $confirmMessage = 'This action cannot be undone.';
    public ?string $pendingMethod = null;
    public array $pendingParams   = [];

    public function needsConfirmation(string $method, mixed ...$params): void
    {
        $this->pendingMethod = $method;
        $this->pendingParams = array_values($params);

        $this->dispatch('open-confirm',
            componentId: $this->getId(),
            title:       $this->confirmTitle,
            message:     $this->confirmMessage,
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
