<?php

// Study-only, removable: see app/Study/README.md. Loaded by StudyServiceProvider
// under /study, named study.*, admins only.

use App\Study\Livewire\ProgressPage;
use Illuminate\Support\Facades\Route;

Route::get('/', ProgressPage::class)->name('progress');
