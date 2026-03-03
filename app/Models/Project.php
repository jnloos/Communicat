<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;

class Project extends Model
{
    public function messages(): HasMany {
        return $this->hasMany(Message::class);
    }

    public function summaries(): HasMany {
        return $this->hasMany(Summary::class);
    }

    public function contributors(): BelongsToMany {
        return $this->belongsToMany(Contributor::class, 'contributor_project');
    }

    public function isPersistent(): bool {
        return $this->contributors()->count() > 1;
    }

    public function addContributingExpert(Expert $expert): void {
        $contributor = Contributor::firstOrCreate(['expert_id' => $expert->id]);
        $this->contributors()->syncWithoutDetaching($contributor->id);
    }

    public function removeContributingExpert(Expert $expert): void {
        if ($contributor = Contributor::where('expert_id', $expert->id)->first()) {
            $this->contributors()->detach($contributor->id);
        }
    }

    public function contributingExperts(): Collection {
        return $this->contributors()->whereNotNull('expert_id')->with('expert')
            ->get()->pluck('expert')->filter();
    }

    public function addContributingUser(User $user): void {
        $contributor = Contributor::firstOrCreate(['user_id' => $user->id]);
        $this->contributors()->syncWithoutDetaching($contributor->id);
    }

    public function removeContributingUser(User $user): void {
        if ($contributor = Contributor::where('user_id', $user->id)->first()) {
            $this->contributors()->detach($contributor->id);
        }
    }

    public function contributingUsers(): Collection {
        return $this->contributors()->whereNotNull('user_id')->with('user')
            ->get()->pluck('user')->filter();
    }

    public function hasContributor(User $user): bool {
        return $this->contributors()
            ->whereHas('user', fn($q) => $q->where('id', $user->id))
            ->exists();
    }

    protected static function booted(): void {
        static::created(function (Project $project): void {
            if (auth()->check()) {
                $contributor = Contributor::firstOrCreate(['user_id' => auth()->id()]);
                $project->contributors()->syncWithoutDetaching($contributor->id);
            }

            $welcomeMsg = view('components.projects.welcome-message', [
                'project' => $project
            ])->render();
            $project->addMessage($welcomeMsg, Contributor::assistant());
        });
    }

    public function addMessage(string $content, Expert|User|Contributor $contributor): Message {
        $message = new Message();
        $message->project_id = $this->id;
        $message->content = $content;

        if(! $contributor instanceof Contributor) {
            $contributor = $contributor->contributor;
        }
        $message->contributor_id = $contributor->id;

        $message->save();
        return $message;
    }

    public function asPromptArray(int $numMsg = -1): array {
        $assistant = Contributor::assistant();

        $query = $this->messages()->where('contributor_id', '!=', $assistant->id)->latest();
        if ($numMsg > -1) {
            $query->take($numMsg);
        }

        $messages = $query->get()->map(fn (Message $msg) => $msg->toPromptArray())->values()->all();
        return [
            'title' => $this->title,
            'description' => $this->description,
            'messages' => $messages
        ];
    }
}
