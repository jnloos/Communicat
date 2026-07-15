<?php

namespace App\Livewire\Experts;

use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\Expert;
use App\Models\Tag;
use App\Support\VoiceCatalog;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

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
    public string $job = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('nullable|string')]
    public string $profile = '';

    public array $coreBeliefs = [];

    public array $knowledgeLimits = [];

    #[Validate('nullable|string')]
    public string $style = '';

    #[Validate('nullable|string|max:500')]
    public string $tagsInput = '';

    #[Validate('required|in:female,male')]
    public string $voiceGender = 'female';

    #[Validate('nullable|string|max:64')]
    public string $voiceId = '';

    #[On('edit_expert')]
    public function edit($id = null): void
    {
        Gate::authorize('admin');

        $this->resetForm();

        $expert = null;
        if (! is_null($id)) {
            $expert = Expert::findOrFail($id);
            $this->expertId = $id;
        }

        $this->name = $expert->name ?? '';
        $this->avatarUrl = $expert->avatar_url ?? null;
        $this->job = $expert->job ?? '';
        $this->description = $expert->description ?? '';
        $this->profile = $expert->profile ?? '';
        $this->coreBeliefs = is_array($expert?->core_beliefs) ? array_values($expert->core_beliefs) : [];
        $this->knowledgeLimits = is_array($expert?->knowledge_limits) ? array_values($expert->knowledge_limits) : [];
        $this->style = $expert->style ?? '';
        $this->tagsInput = $expert
            ? $expert->tags()->orderBy('name')->pluck('name')->implode(', ')
            : '';

        $this->voiceId = $expert->voice_id ?? '';
        $this->voiceGender = $this->resolveGenderForVoice($this->voiceId) ?? 'female';

        Flux::modal('edit-expert')->show();
    }

    public function updatedVoiceGender(): void
    {
        // Reset voice selection so the dropdown doesn't show a value from the
        // other gender's list. The user actively picks a new voice.
        if ($this->voiceId !== '' && $this->resolveGenderForVoice($this->voiceId) !== $this->voiceGender) {
            $this->voiceId = '';
        }
    }

    protected function resolveGenderForVoice(?string $voiceId): ?string
    {
        return VoiceCatalog::genderFor($voiceId);
    }

    public function save(): void
    {
        Gate::authorize('admin');
        $this->validate();

        $expert = $this->expertId ? Expert::findOrFail($this->expertId) : new Expert;

        $expert->name = $this->name;
        $expert->job = $this->job;
        $expert->description = $this->description;
        $expert->profile = $this->profile;
        $expert->core_beliefs = array_values(array_filter($this->coreBeliefs, fn ($v) => trim((string) $v) !== ''));
        $expert->knowledge_limits = array_values(array_filter($this->knowledgeLimits, fn ($v) => trim((string) $v) !== ''));
        $expert->style = $this->style;
        $expert->voice_id = $this->voiceId !== '' ? $this->voiceId : null;
        $expert->save();

        if (! is_null($this->avatarUpload)) {
            $this->deleteAvatar($expert->avatar_url);
            $expert->avatar_url = $this->storeAvatar($expert->id);
            $expert->save();
        }

        $tagIds = collect(explode(',', $this->tagsInput))
            ->map(fn ($name) => trim($name))
            ->filter(fn ($name) => $name !== '')
            ->unique()
            ->map(fn (string $name) => Tag::firstOrCreateByName($name)->id)
            ->unique()
            ->values()
            ->all();

        $expert->tags()->sync($tagIds);

        $this->expertId = $expert->id;
        $this->avatarUrl = $expert->avatar_url;

        $this->resetForm();
        Flux::modal('edit-expert')->close();
        $this->dispatch('expert_modified');
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

    public function delete(): void
    {
        Gate::authorize('admin');

        if (! is_null($this->expertId)) {
            $expert = Expert::findOrFail($this->expertId);
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

    public function addCoreBelief(): void
    {
        $this->coreBeliefs[] = '';
    }

    public function removeCoreBelief(int $index): void
    {
        array_splice($this->coreBeliefs, $index, 1);
        $this->coreBeliefs = array_values($this->coreBeliefs);
    }

    public function addKnowledgeLimit(): void
    {
        $this->knowledgeLimits[] = '';
    }

    public function removeKnowledgeLimit(int $index): void
    {
        array_splice($this->knowledgeLimits, $index, 1);
        $this->knowledgeLimits = array_values($this->knowledgeLimits);
    }

    protected function resetForm(): void
    {
        $this->reset(['expertId', 'name', 'avatarUpload', 'avatarUrl', 'job', 'description', 'profile', 'style', 'tagsInput', 'voiceId', 'voiceGender']);
        $this->coreBeliefs = [];
        $this->knowledgeLimits = [];
    }

    public function render(): mixed
    {
        $isUpdate = ! is_null($this->expertId);

        // Show only the voice's traits in the dropdown: strip the name prefix
        // before the en-dash ("Sarah – warm, ruhig" → "warm, ruhig").
        $voices = collect((array) config("voices.$this->voiceGender", []))
            ->map(fn ($v) => [
                'id'    => $v['id'] ?? '',
                'label' => str_contains((string) ($v['label'] ?? ''), '–')
                    ? trim(\Illuminate\Support\Str::after((string) $v['label'], '–'))
                    : (string) ($v['label'] ?? ''),
            ])
            ->all();

        // Preserve a current value that is not in the active gender's list
        // (legacy data or gender mismatch) so saving doesn't drop it silently.
        if ($this->voiceId !== '' && ! collect($voices)->contains(fn($v) => ($v['id'] ?? null) === $this->voiceId)) {
            $voices = array_merge(
                [['id' => $this->voiceId, 'label' => __('— aktuelle Stimme: ') . $this->voiceId]],
                $voices
            );
        }

        return view('livewire.experts.expert-editor', [
            'isUpdate' => $isUpdate,
            'voices' => $voices,
        ]);
    }
}
