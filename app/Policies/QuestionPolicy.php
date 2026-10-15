<?php

namespace App\Policies;

use App\Models\Question;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The rules of the statistics page. A user reads their own questions and
 * every template; someone else's question does not exist for them, so that
 * denial is a 404, never a 403. A template is read by everyone, so only an
 * admin saves, orders, renames or deletes one, and renaming or deleting also
 * needs ownership. Question::scopeVisibleTo() is view() as a query.
 */
class QuestionPolicy
{
    public function view(User $user, Question $question): Response
    {
        return $question->is_template || $this->owns($user, $question)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /** Renaming, and saving the definition in the editor. */
    public function update(User $user, Question $question): Response
    {
        if (! $this->owns($user, $question)) {
            return Response::denyAsNotFound();
        }

        return ! $question->is_template || $this->manageTemplates($user) ? Response::allow() : Response::deny();
    }

    public function delete(User $user, Question $question): Response
    {
        return $this->update($user, $question);
    }

    /** Duplicating and ordering apply to the user's own questions; a template is neither. */
    public function duplicate(User $user, Question $question): Response
    {
        return ! $question->is_template && $this->owns($user, $question)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function reorder(User $user, Question $question): Response
    {
        return $this->duplicate($user, $question);
    }

    /** Saving templates and setting their order, which is the same for everyone. */
    public function manageTemplates(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    /** A snapshot of one of the user's own rows as a template. */
    public function saveAsTemplate(User $user, Question $question): Response
    {
        if (! $this->manageTemplates($user)) {
            return Response::deny();
        }

        return $this->owns($user, $question) ? Response::allow() : Response::denyAsNotFound();
    }

    private function owns(User $user, Question $question): bool
    {
        return $question->user_id === $user->id;
    }
}
