<?php

namespace App\Discussion\Support;

use App\Models\Project;

class PromptRenderer
{
    /**
     * Blade's {{ }} HTML-escapes values ("Devil&#039;s Advocate"). Prompts are
     * plain text, so entities are decoded once after rendering.
     */
    public function render(string $view, array $data = []): string
    {
        return html_entity_decode(trim(view($view, $data)->render()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function system(): string
    {
        return $this->render('prompts.system');
    }

    /** The token → name roster every agent prompt includes. */
    public function participants(Project $project): array
    {
        return [
            'experts' => $project->contributingExperts(),
            'users' => $project->users()->get()->push($project->owner)->filter()->unique('id')->values(),
        ];
    }
}
