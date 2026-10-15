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

    /** The raw data of exactly the runs a question selects; authorized on the route (`can:view,question`): an own question or any template. */
    public function __invoke(Question $question, QuestionRuns $runs, StudyExport $export): BinaryFileResponse
    {
        $user = auth()->user();

        return $this->downloadTemporary(
            fn (string $path) => $export->write($runs->for($user, $question->definition()->projects), $user, $path),
            $export->filename($question->name ?? __('questions.untitled')),
        );
    }
}
