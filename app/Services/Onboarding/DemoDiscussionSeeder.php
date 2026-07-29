<?php

namespace App\Services\Onboarding;

use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds the single, shared, read-only onboarding demo discussion the welcome
 * tour runs on. Unlike ProjectTransfer\ProjectImporter this links experts BY
 * NAME against the existing experts table — robust against unstable ids — and
 * performs NO LLM calls (all content comes from database/demo-discussion.json).
 *
 * The demo is flagged with settings['is_demo'] = true, which the access-project
 * gate uses to grant every authenticated user read access, and which the chat
 * controls use to stay read-only. Seeding is idempotent: an existing demo is
 * refreshed in place (messages/summaries replaced), keeping a stable URL.
 */
class DemoDiscussionSeeder
{
    public const SETTINGS_FLAG = 'is_demo';

    /** Names referenced in the template but not present in the experts table. */
    protected array $missingExperts = [];

    /**
     * (Re)build the demo project from the template. Returns the persisted Project.
     */
    public function seed(?User $owner = null, ?string $templatePath = null): Project
    {
        $owner ??= $this->defaultOwner();
        $path = $templatePath ?? database_path('demo-discussion.json');

        if (! is_file($path)) {
            throw new RuntimeException("Demo template not found: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $experts = $this->resolveExperts($data['experts'] ?? []);
        if ($experts->isEmpty()) {
            throw new RuntimeException('No demo experts found — run `php artisan init:experts` first.');
        }

        return DB::transaction(function () use ($data, $owner, $experts) {
            $project = $this->existingDemo() ?? new Project;
            $project->user_id = $owner->id;
            $project->title = (string) ($data['project']['title'] ?? 'Demo');
            $project->description = (string) ($data['project']['description'] ?? '');

            $settings = (array) ($data['project']['settings'] ?? []);
            $settings[self::SETTINGS_FLAG] = true;
            $project->settings = $settings;
            $project->save(); // created hook adds the owner as a contributor

            // Idempotent refresh: drop any previous demo content.
            $project->messages()->delete();
            $project->summaries()->delete();

            // Contributors: exactly the demo experts. sync() only touches Expert
            // rows of the polymorphic pivot, so the owner-user link is untouched.
            $project->experts()->sync($experts->pluck('id')->all());

            $ownerName = $owner->name;
            $messages = array_values((array) ($data['messages'] ?? []));
            $base = Carbon::now()->subMinutes(count($messages) + 1);

            foreach ($messages as $i => $m) {
                $this->makeMessage($project, $experts, $owner, $ownerName, (array) $m, $base->copy()->addMinutes($i));
            }

            foreach ((array) ($data['summaries'] ?? []) as $name => $content) {
                $expert = $experts->get($name);
                if ($expert === null) {
                    continue;
                }
                Summary::updateOrCreate(
                    ['project_id' => $project->id, 'expert_id' => $expert->id],
                    ['content' => str_replace('%OWNER%', $ownerName, (string) $content)],
                );
            }

            return $project->refresh();
        });
    }

    /** The already-seeded shared demo project, if any. */
    public function existingDemo(): ?Project
    {
        return Project::query()->where('settings->'.self::SETTINGS_FLAG, true)->first();
    }

    /** @return array<int, string> names from the template missing in the DB */
    public function missingExperts(): array
    {
        return $this->missingExperts;
    }

    /**
     * Resolve the template's expert names to models, keyed by name. Missing names
     * are recorded (not fatal) so the command can prompt to run init:experts.
     *
     * @param  array<int, string>  $names
     * @return Collection<string, Expert>
     */
    protected function resolveExperts(array $names): Collection
    {
        $found = Expert::whereIn('name', $names)->get()->keyBy('name');
        $this->missingExperts = array_values(array_diff($names, $found->keys()->all()));

        return $found;
    }

    /**
     * Create one demo message. `speaker`/`addressed` are names, or the literal
     * "user" for the human owner. Unknown speakers are skipped.
     *
     * @param  Collection<string, Expert>  $experts
     * @param  array<string, mixed>  $m
     */
    protected function makeMessage(Project $project, Collection $experts, User $owner, string $ownerName, array $m, Carbon $at): void
    {
        $speaker = (string) ($m['speaker'] ?? '');

        $message = new Message;
        $message->project_id = $project->id;
        $message->content = str_replace('%OWNER%', $ownerName, (string) ($m['content'] ?? ''));

        if ($speaker === 'user') {
            $message->user_id = $owner->id;
        } elseif ($expert = $experts->get($speaker)) {
            $message->expert_id = $expert->id;
        } else {
            return; // unknown speaker → skip rather than emit a ghost message
        }

        $message->adjacency_pair_type = $m['pair_type'] ?? null;

        $addressed = $m['addressed'] ?? null;
        if ($addressed === 'user') {
            $message->adjacency_partner_type = User::class;
            $message->adjacency_partner_id = $owner->id;
        } elseif ($addressed !== null && ($partner = $experts->get($addressed))) {
            $message->adjacency_partner_type = Expert::class;
            $message->adjacency_partner_id = $partner->id;
        }

        $message->created_at = $at;
        $message->updated_at = $at;
        $message->save();
    }

    /** Owner for the demo: first admin, else first user. */
    protected function defaultOwner(): User
    {
        $owner = User::where('is_admin', true)->first() ?? User::query()->orderBy('id')->first();

        if ($owner === null) {
            throw new RuntimeException('No user exists to own the demo — create a user first.');
        }

        return $owner;
    }
}
