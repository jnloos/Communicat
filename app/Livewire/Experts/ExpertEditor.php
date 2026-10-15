<?php

namespace App\Livewire\Experts;

use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\Expert;
use App\Models\TagCategory;
use App\Models\TagScope;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The expert flyout: every verified user opens it to read a persona, only an
 * admin creates, changes or deletes one (ExpertPolicy). For everyone else it
 * shows the same fields read only.
 */
class ExpertEditor extends Component
{
    use NeedsConfirmation, WithFileUploads;

    #[Locked]
    public ?int $expertId = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|image|max:2048')]
    public $avatarUpload = null;

    #[Locked]
    public ?string $avatarUrl = null;

    #[Validate('required|string|max:255')]
    public string $role = '';

    #[Validate('nullable|string')]
    public string $description = '';

    /** @var array<int|string, int|string|null> bound by x-tags.fields; category id => value id */
    public array $tagSelection = [];

    #[On('edit_expert')]
    public function edit($id = null): void
    {
        $this->resetForm();

        $expert = null;
        if (is_null($id)) {
            $this->authorize('create', Expert::class);
        } else {
            $expert = Expert::findOrFail($id);
            $this->expertId = $expert->id;
        }

        $this->name = $expert->name ?? '';
        $this->avatarUrl = $expert->avatar_url ?? null;
        $this->role = $expert->role ?? '';
        $this->description = $expert->description ?? '';
        $this->tagSelection = $expert?->tagSelection() ?? [];

        Flux::modal('edit-expert')->show();
    }

    public function save(): void
    {
        $expert = $this->authorizedForSaving();
        $this->validate();

        $expert->name = $this->name;
        $expert->role = $this->role;
        $expert->description = $this->description;

        // One transaction, so a forged tag value leaves no new or half-edited expert behind.
        DB::transaction(function () use ($expert) {
            $expert->save();
            $expert->syncTags($this->tagSelection);
        });

        if (! is_null($this->avatarUpload)) {
            $this->deleteAvatar($expert->avatar_url);
            $expert->avatar_url = $this->storeAvatar($expert->id);
            $expert->save();
        }

        $this->expertId = $expert->id;
        $this->avatarUrl = $expert->avatar_url;

        $this->resetForm();
        Flux::modal('edit-expert')->close();
        $this->dispatch('expert_modified');
    }

    private function authorizedForSaving(): Expert
    {
        if (is_null($this->expertId)) {
            $this->authorize('create', Expert::class);

            return new Expert;
        }

        $expert = Expert::findOrFail($this->expertId);
        $this->authorize('update', $expert);

        return $expert;
    }

    public function updatedAvatarUpload(): void
    {
        if (! is_null($this->avatarUpload)) {
            $this->avatarUrl = $this->avatarUpload->temporaryUrl();
            $this->dispatch('$refresh');
        }
    }

    protected function storeAvatar(int $expertId): string
    {
        $extension = $this->avatarUpload->getClientOriginalExtension();
        $filename = "expert-$expertId-avatar-".time().".$extension";
        $path = $this->avatarUpload->storeAs(path: '/avatars/custom', name: $filename, options: 'public');

        return Storage::url($path);
    }

    /** Confirmed, so called directly, past Livewire's attributes: it authorizes itself. */
    public function delete(): void
    {
        if (! is_null($this->expertId)) {
            $expert = Expert::findOrFail($this->expertId);
            $this->authorize('delete', $expert);
            $this->deleteAvatar($expert->avatar_url);
            $expert->delete();

            $this->resetForm();
            Flux::modal('edit-expert')->close();
            $this->dispatch('expert_modified');
        }
    }

    protected function deleteAvatar(?string $url): void
    {
        if (is_null($url)) {
            return;
        }
        if (str_contains($url, 'public/avatars/static')) {
            return;
        }
        $filename = basename(parse_url($url, PHP_URL_PATH));
        $path = '/avatars/custom/'.$filename;
        Storage::disk('public')->delete($path);
    }

    protected function resetForm(): void
    {
        $this->reset(['expertId', 'name', 'avatarUpload', 'avatarUrl', 'role', 'description', 'tagSelection']);
    }

    public function render(): mixed
    {
        $expert = is_null($this->expertId) ? null : Expert::find($this->expertId);

        return view('livewire.experts.expert-editor', [
            'isUpdate' => ! is_null($this->expertId),
            'mayEdit' => $expert === null ? Gate::allows('create', Expert::class) : Gate::allows('update', $expert),
            'mayDelete' => $expert !== null && Gate::allows('delete', $expert),
            'tagCategories' => TagCategory::ofScope(TagScope::Persona)->with('values')->get(),
        ]);
    }
}
