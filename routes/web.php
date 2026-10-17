<?php

use App\Livewire\Chat\ChatWindow;
use App\Livewire\Dashboard\HealthDashboard;
use App\Livewire\Projects\ThresholdManager;
use App\Livewire\Settings\AiSettingsForm;
use App\Models\Project;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', HealthDashboard::class)->name('dashboard');
Route::get('/chat', ChatWindow::class)->name('chat');

Route::get('/settings/ai', AiSettingsForm::class)->name('settings.ai');
Route::get('/thresholds', ThresholdManager::class)->name('thresholds');

Route::get('/switch-project/{project}', function (Project $project) {
    session(['active_project_id' => $project->id]);

    return redirect()->route('dashboard');
})->name('switch-project');
