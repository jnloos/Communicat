<?php

use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\Password;
use App\Livewire\Settings\Profile;
use App\Models\Project;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
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
    });


Route::get('experts', function () {
    return view('experts');
})->middleware(['auth', 'verified'])->name('experts');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', Profile::class)->name('settings.profile');
    Route::get('settings/password', Password::class)->name('settings.password');
    Route::get('settings/appearance', Appearance::class)->name('settings.appearance');
});

require __DIR__.'/auth.php';
