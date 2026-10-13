<?php

namespace App\Http\Controllers;

use App\Discussion\Metrics\Questions\QuestionRuns;
use App\Http\Controllers\Concerns\DownloadsTemporaryFile;
use App\Models\Question;
use App\Services\StudyExport\StudyExport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudyExportController extends Controller
{
    use DownloadsTemporaryFile;

    /** The raw data of exactly the runs a question selects; an own question or any template. */
    public function __invoke(int $question, QuestionRuns $runs, StudyExport $export): BinaryFileResponse
    {
        $user = auth()->user();
        $found = $user->questions()->notTemplates()->find($question) ?? Question::templates()->find($question) ?? abort(404);

        return $this->downloadTemporary(
            fn (string $path) => $export->write($runs->for($user, $found->definition()->projects), $user, $path),
            $export->filename($found->name ?? __('questions.untitled')),
        );
    }
}
