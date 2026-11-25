<?php

// Thesis study, see docs/study.md. Loaded by App\Providers\StudyServiceProvider while STUDY_MODULE is on,
// under /study, named study.*, admins only.

use App\Livewire\Study\PlanWavePage;
use App\Livewire\Study\ProgressPage;
use Illuminate\Support\Facades\Route;

Route::get('/', ProgressPage::class)->name('progress');
Route::get('/plan', PlanWavePage::class)->name('plan');
