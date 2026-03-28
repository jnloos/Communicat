<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Project extends Model
{
    protected $fillable = ['title', 'description', 'settings'];

    protected $casts = ['settings' => 'array'];

    public function messages(): HasMany {
        return $this->hasMany(Message::class);
    }

    public function summaries(): HasMany {
        return $this->hasMany(Summary::class);
    }

    public function experts(): BelongsToMany {
        return $this->belongsToMany(Expert::class, 'expert_project');
    }

    public function users(): BelongsToMany {
        return $this->belongsToMany(User::class, 'user_project');
    }

    public function isPersistent(): bool {
        return $this->experts()->count() > 0 || $this->users()->count() > 1;
    }

    public function addContributingExpert(Expert $expert): void {
        $this->experts()->syncWithoutDetaching($expert->id);
    }

    public function removeContributingExpert(Expert $expert): void {
        $this->experts()->detach($expert->id);
    }

    public function contributingExperts(): Collection {
        return $this->experts()->get();
    }

    public function addContributingUser(User $user): void {
        $this->users()->syncWithoutDetaching($user->id);
    }

    public function removeContributingUser(User $user): void {
        $this->users()->detach($user->id);
    }

    public function contributingUsers(): Collection {
        return $this->users()->get();
    }

    public function hasContributor(User $user): bool {
        return $this->users()->whereKey($user->id)->exists();
    }

    protected static function booted(): void {
        static::created(function (Project $project): void {
            if (auth()->check()) {
                $project->users()->syncWithoutDetaching(auth()->id());
            }

            $welcomeMsg = view('components.projects.welcome-message', [
                'project' => $project
            ])->render();
            $project->addMessage($welcomeMsg);
        });
    }

    public function addMessage(string $content, Expert|User|null $sender = null): Message {
        $message = new Message();
        $message->project_id = $this->id;
        $message->content = $content;

        if ($sender instanceof Expert) {
            $message->expert_id = $sender->id;
        } elseif ($sender instanceof User) {
            $message->user_id = $sender->id;
        }

        $message->save();
        return $message;
    }

    public function asPromptArray(int $numMsg = -1): array {
        $lastSummarizedId = $this->settings['last_summarized_id'] ?? 0;

        $query = $this->messages()
            ->where(function ($q) {
                $q->whereNotNull('expert_id')->orWhereNotNull('user_id');
            })
            ->where('id', '>', $lastSummarizedId)
            ->latest();

        if ($numMsg > -1) {
            $query->take($numMsg);
        }

        $messages = $query->get()->map(fn(Message $msg) => $msg->toPromptArray())->values()->all();

        return [
            'title'       => $this->title,
            'description' => $this->description,
            'messages'    => $messages,
        ];
    }
}
