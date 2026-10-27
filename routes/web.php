<?php

use App\Http\Controllers\AdminSettingsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/inventory', [DashboardController::class, 'saveInventory'])->middleware('role:administrator,accountant,operations')->name('inventory.save');
    Route::post('/import-gl', [DashboardController::class, 'importGl'])->middleware('role:administrator,accountant')->name('gl.import');
    Route::get('/export-matrix', [DashboardController::class, 'exportMatrix'])->name('matrix.export');
});

Route::middleware(['auth', 'administrator'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/settings', [AdminSettingsController::class, 'edit'])->name('settings');
    Route::post('/settings', [AdminSettingsController::class, 'update'])->name('settings.update');
    Route::post('/users', [AdminSettingsController::class, 'storeUser'])->name('users.store');
    Route::patch('/users/{user}', [AdminSettingsController::class, 'updateUser'])->name('users.update');
});
