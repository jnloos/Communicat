<?php

namespace App\Livewire\Settings;

use Livewire\Component;

class Appearance extends Component
{
    public function render(): mixed
    {
        return view('livewire.settings.appearance')->title(__('settings.nav.appearance'));
    }
}
