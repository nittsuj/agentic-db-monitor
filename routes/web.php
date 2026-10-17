<?php

use App\Livewire\Dashboard\HealthDashboard;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', HealthDashboard::class)->name('dashboard');
