<?php

use App\Livewire\Dashboard\HealthDashboard;
use App\Models\Project;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', HealthDashboard::class)->name('dashboard');

Route::get('/switch-project/{project}', function (Project $project) {
    session(['active_project_id' => $project->id]);

    return redirect()->route('dashboard');
})->name('switch-project');
