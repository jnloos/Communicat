<?php

use App\Http\Controllers\BundleExportController;
use App\Http\Controllers\StudyExportController;
use App\Livewire\Debug\JobDebugPanel;
use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\Password;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\Tags;
use App\Livewire\Settings\Transfer;
use App\Livewire\Settings\Users;
use App\Livewire\Statistics\StatisticsPage;
use App\Models\Project;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // Redirect to last project
    $projectId = Cookie::get('curr_project');
    if ($projectId && Project::find($projectId)) {
        return redirect()->route('project.show', ['project' => $projectId]);
    }

    // Redirect to project creation
    return redirect()->route('project.new');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])
    ->prefix('projects')
    ->name('project.')
    ->group(function () {
        // Project creation path
        Route::get('new', function () {
            return view('index');
        })->name('new');

        // Project access path
        Route::get('{project}', function (Project $project) {
            if (! Gate::allows('access-project', $project)) {
                return redirect()->route('project.new');
            }

            Cookie::queue('curr_project', str($project->id), minutes: 60 * 24 * 31);

            return view('project', compact('project'));
        })->name('show');

        Route::get('{project}/export', [BundleExportController::class, 'project'])->name('export');
    });

Route::get('experts', function () {
    return view('experts');
})->middleware(['auth', 'verified'])->name('experts');

Route::get('experts/{expert}/export', [BundleExportController::class, 'expert'])
    ->middleware(['auth', 'verified'])->name('experts.export');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', Profile::class)->name('settings.profile');
    Route::get('settings/password', Password::class)->name('settings.password');
    Route::get('settings/appearance', Appearance::class)->name('settings.appearance');
    Route::get('settings/users', Users::class)->can('admin')->name('settings.users');
    Route::get('settings/tags', Tags::class)->can('admin')->name('settings.tags');
    Route::get('settings/transfer', Transfer::class)->can('admin')->name('settings.transfer');
    Route::get('settings/export', [BundleExportController::class, 'everything'])->can('admin')->name('settings.export');
    Route::get('statistics', StatisticsPage::class)->middleware('verified')->name('statistics');
    Route::get('statistics/{question}/export', StudyExportController::class)->middleware('verified')->whereNumber('question')->name('statistics.export');

    Route::get('debug/jobs', JobDebugPanel::class)->name('debug.jobs');
});

require __DIR__.'/auth.php';
