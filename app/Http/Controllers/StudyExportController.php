<?php

namespace App\Http\Controllers;

use App\Discussion\Metrics\Questions\QuestionRuns;
use App\Models\Question;
use App\Services\StudyExport\StudyExport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class StudyExportController extends Controller
{
    /** The raw data of exactly the runs a question selects; an own question or any template. */
    public function __invoke(int $question, QuestionRuns $runs, StudyExport $export): BinaryFileResponse
    {
        $user = auth()->user();
        $found = $user->questions()->notTemplates()->find($question) ?? Question::templates()->find($question) ?? abort(404);
        $path = tempnam(sys_get_temp_dir(), 'study');

        try {
            $export->write($runs->for($user, $found->definition()->projects), $user, $path);
        } catch (Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return response()->download($path, $export->filename($found->name ?? __('questions.untitled')), ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
