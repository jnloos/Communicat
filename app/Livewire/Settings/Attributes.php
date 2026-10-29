<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\PersonaAttribute;
use App\Services\Tags\TagNames;
use Closure;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Settings → Attributes: the register of attributes an expert can carry
 * (origin, gender, …) with the two sentence templates that turn a value into
 * prompt text. Admins only, checked in boot() so it covers every request, not
 * just the first one the route middleware sees.
 *
 * The Role definition is part of the study's manipulation (title or none) and is
 * referenced by name in code, bundles and runs, so it can be neither renamed nor
 * deleted here. Its two templates stay editable: they are frozen into a run's
 * run_config at its first turn, so a changed wording touches no run that has
 * started, and `:value` is required like for every other definition.
 */
class Attributes extends Component
{
    use NeedsConfirmation;

    private const MODAL = 'persona-attribute';

    /** Everything the dialog holds; cleared on open and after a save. */
    private const FORM_FIELDS = ['name', 'selfTemplate', 'rosterTemplate', 'previewValue'];

    /** null while the dialog creates a new attribute. */
    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $selfTemplate = '';

    public string $rosterTemplate = '';

    /** Typed by the admin to see the templates rendered; never stored. */
    public string $previewValue = '';

    public function boot(): void
    {
        Gate::authorize('admin');
    }

    /** A corrected field loses its red border and message at once, not only after the next save. */
    public function updated(string $property): void
    {
        if (in_array($property, ['name', 'selfTemplate', 'rosterTemplate'], true)) {
            $this->resetValidation($property);
        }
    }

    /** Opens the dialog empty (no id) or filled with one attribute. */
    public function edit(?int $attributeId = null): void
    {
        $this->resetValidation();
        $this->reset(self::FORM_FIELDS);
        $this->editingId = null;

        if ($attributeId !== null) {
            $attribute = PersonaAttribute::findOrFail($attributeId);
            $this->editingId = $attribute->id;
            $this->name = $attribute->name;
            $this->selfTemplate = $attribute->self_template;
            $this->rosterTemplate = $attribute->roster_template;
        }

        Flux::modal(self::MODAL)->show();
    }

    public function save(): void
    {
        $this->name = trim($this->name);
        $this->selfTemplate = trim($this->selfTemplate);
        $this->rosterTemplate = trim($this->rosterTemplate);

        $this->validate($this->rules(), [], [
            'name' => __('attributes.fields.name'),
            'selfTemplate' => __('attributes.fields.self_template'),
            'rosterTemplate' => __('attributes.fields.roster_template'),
        ]);

        if ($this->editingIsRole() && ! TagNames::same($this->name, PersonaAttribute::ROLE)) {
            $this->addError('name', __('attributes.errors.role_locked'));

            return;
        }

        $fields = ['name' => $this->name, 'self_template' => $this->selfTemplate, 'roster_template' => $this->rosterTemplate];

        if ($this->editingId === null) {
            PersonaAttribute::create($fields);
        } else {
            PersonaAttribute::findOrFail($this->editingId)->update($fields);
        }

        Flux::modal(self::MODAL)->close();
        $this->reset([...self::FORM_FIELDS, 'editingId']);
    }

    public function confirmDelete(int $attributeId): void
    {
        $attribute = PersonaAttribute::withCount('experts')->findOrFail($attributeId);
        $this->refuseRoleDeletion($attribute);

        $this->confirmTitle = __('attributes.confirm.title');
        $this->confirmLabel = __('attributes.confirm.action');
        $this->confirmMessage = trans_choice('attributes.confirm.message', $attribute->experts_count, [
            'name' => $attribute->name,
            'count' => $attribute->experts_count,
        ]);
        $this->needsConfirmation('delete', $attributeId);
    }

    /** The confirm target: runs past Livewire's own checks, so the gate is asked again here. */
    public function delete(int $attributeId): void
    {
        Gate::authorize('admin');

        $attribute = PersonaAttribute::findOrFail($attributeId);
        $this->refuseRoleDeletion($attribute);

        $attribute->delete();
    }

    /** Answered 403 whatever the client sends: the UI offers no delete for the Role, so this is never a normal path. */
    private function refuseRoleDeletion(PersonaAttribute $attribute): void
    {
        abort_if($attribute->isRole(), 403, __('attributes.errors.role_locked'));
    }

    /** Whether the dialog edits the Role definition (read by the view to lock the name field). */
    public function editingIsRole(): bool
    {
        return $this->editingId !== null && PersonaAttribute::find($this->editingId)?->isRole() === true;
    }

    /** wire:sort hands over the item key and its new 0-based position. */
    public function sort(int $attributeId, int $position): void
    {
        PersonaAttribute::findOrFail($attributeId)->moveTo($position);
    }

    /** Move up/down in the row menu, the way to sort without dragging: negative steps go up. */
    public function move(int $attributeId, int $steps): void
    {
        PersonaAttribute::findOrFail($attributeId)->moveBy($steps);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.PersonaAttribute::NAME_MAX, function (string $attribute, mixed $value, Closure $fail) {
                if (PersonaAttribute::nameTaken((string) $value, $this->editingId)) {
                    $fail('validation.unique')->translate();
                }
            }],
            'selfTemplate' => ['required', 'string', 'max:'.PersonaAttribute::TEMPLATE_MAX, $this->containsPlaceholder()],
            'rosterTemplate' => ['required', 'string', 'max:'.PersonaAttribute::TEMPLATE_MAX, $this->containsPlaceholder()],
        ];
    }

    /** Without it the value would never reach the prompt; the model itself does not check. */
    private function containsPlaceholder(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! str_contains((string) $value, PersonaAttribute::PLACEHOLDER)) {
                $fail('attributes.errors.missing_value')->translate(['placeholder' => PersonaAttribute::PLACEHOLDER]);
            }
        };
    }

    public function render(): mixed
    {
        $example = trim($this->previewValue) !== '' ? trim($this->previewValue) : __('attributes.preview.example');
        // Render with the same replacement the prompts use, on an unsaved instance.
        $draft = new PersonaAttribute(['self_template' => $this->selfTemplate, 'roster_template' => $this->rosterTemplate]);

        return view('livewire.settings.attributes', [
            'definitions' => PersonaAttribute::withCount('experts')->ordered()->get(),
            'previewSelf' => $this->selfTemplate === '' ? null : $draft->selfLine($example),
            'previewRoster' => $this->rosterTemplate === '' ? null : $draft->rosterText($example),
        ])->title(__('attributes.heading'));
    }
}
