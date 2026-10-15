<?php

namespace App\Livewire\Forms;

use App\Models\ProjectGroup;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * The name of a sidebar group. Groups are private, so a name has to be unique
 * among the signed-in user's own groups only.
 */
class GroupNameForm extends Form
{
    public string $name = '';

    public function fillFrom(ProjectGroup $group): void
    {
        $this->name = $group->name;
    }

    /**
     * Validates and returns the name as it is stored, trimmed. The group being
     * renamed is passed so that keeping its own name is no clash.
     */
    public function validatedName(?ProjectGroup $renaming = null): string
    {
        $this->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('project_groups', 'name')
                    ->where('user_id', auth()->id())
                    ->ignore($renaming?->id),
            ],
        ]);

        return trim($this->name);
    }
}
