<?php

use App\Livewire\Approvals\ApprovalQueue;
use App\Livewire\Chat\ChatWindow;
use App\Livewire\Dashboard\HealthDashboard;
use App\Livewire\Projects\ThresholdManager;
use App\Livewire\Settings\AiSettingsForm;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', HealthDashboard::class)->name('dashboard');
Route::get('/chat', ChatWindow::class)->name('chat');
Route::get('/approvals', ApprovalQueue::class)->name('approvals');

Route::get('/settings/ai', AiSettingsForm::class)->name('settings.ai');
Route::get('/thresholds', ThresholdManager::class)->name('thresholds');

Route::get('/switch-project/{project}', function (Request $request, Project $project) {
    session(['active_project_id' => $project->id]);

    // Only known pages are accepted as a return target, never an arbitrary URL.
    $target = $request->query('to');
    $allowed = ['dashboard', 'chat', 'approvals', 'thresholds'];

    return redirect()->route(in_array($target, $allowed, true) ? $target : 'dashboard');
})->name('switch-project');
