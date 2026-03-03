<?php

namespace App\Livewire\Experts;

use App\Models\Expert;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

class ExpertEditor extends Component
{
    use WithFileUploads;

    #[Locked]
    public int|null $expertId = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|image|max:2048')]
    public $avatarUpload = null;

    #[Locked]
    public ?string $avatarUrl = null;

    #[Validate('required|string|max:255')]
    public string $job = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('nullable|string')]
    public string $prompt = '';

    #[On('edit_expert')]
    public function edit($id = null): void {
        $this->resetForm();

        $expert = null;
        if (!is_null($id)) {
            $expert = Expert::findOrFail($id);
            $this->expertId = $id;
        }

        $this->name = $expert->name ?? '';
        $this->avatarUrl = $expert->avatar_url ?? null;
        $this->job = $expert->job ?? '';
        $this->description = $expert->description ?? '';
        $this->prompt = $expert->prompt ?? '';

        Flux::modal('edit-expert')->show();
    }

    public function save(): void {
        $this->validate();

        $expert = $this->expertId ? Expert::findOrFail($this->expertId) : new Expert();

        $expert->name = $this->name;
        $expert->job = $this->job;
        $expert->description = $this->description;
        $expert->prompt = $this->prompt;
        $expert->save();

        if (!is_null($this->avatarUpload)) {
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

    public function updatedAvatarUpload(): void {
        if(!is_null($this->avatarUpload)) {
            $this->avatarUrl = $this->avatarUpload->temporaryUrl();
            $this->dispatch('$refresh');
        }
    }

    protected function storeAvatar(int $expertId): string {
        $extension = $this->avatarUpload->getClientOriginalExtension();
        $filename = "expert-$expertId-avatar-" . time() . ".$extension";
        $path = $this->avatarUpload->storeAs(path: '/avatars/custom', name: $filename, options: 'public');
        return Storage::url($path);
    }

    public function delete(): void {
        if (!is_null($this->expertId)) {
            $expert = Expert::findOrFail($this->expertId);
            $this->deleteAvatar($expert->avatar_url);
            $expert->delete();

            $this->resetForm();
            Flux::modal('edit-expert')->close();
            $this->dispatch('expert_modified');
        }
    }

    protected function deleteAvatar(?string $url): void {
        if(is_null($url)) return;
        if (str_contains($url, 'public/avatars/static')) return;
        $filename = basename(parse_url($url, PHP_URL_PATH));
        $path = '/avatars/custom/' . $filename;
        Storage::disk('public')->delete($path);
    }

    protected function resetForm(): void {
        $this->reset(['expertId', 'name', 'avatarUpload', 'avatarUrl', 'job', 'description', 'prompt']);
    }

    public function render(): mixed {
        $isUpdate = !is_null($this->expertId);
        return view('livewire.experts.expert-editor', [
            'isUpdate' => $isUpdate
        ]);
    }
}


