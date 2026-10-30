<?php

use App\Http\Controllers\AdminSettingsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\D365DataController;
use App\Http\Controllers\D365SyncController;
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
    Route::post('/d365/sync', [D365SyncController::class, 'sync'])->middleware('role:administrator,accountant')->name('d365.sync');
    Route::get('/d365/progress', [D365SyncController::class, 'progress'])->name('d365.progress');
    Route::get('/export-matrix', [DashboardController::class, 'exportMatrix'])->name('matrix.export');

    // Browser data tampungan MSD D365.
    Route::prefix('d365/data')->name('d365.data.')->group(function () {
        Route::get('/', [D365DataController::class, 'index'])->name('index');
        Route::get('/staging', [D365DataController::class, 'staging'])->name('staging');
        Route::get('/staging/export-xls', [D365DataController::class, 'exportStagingXls'])->name('staging.export_xls');
        Route::get('/main-account-summary', [D365DataController::class, 'mainAccountSummary'])->name('main_account_summary');
        Route::get('/{entityKey}', [D365DataController::class, 'show'])->name('show');
    });
});

Route::middleware(['auth', 'administrator'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/settings', [AdminSettingsController::class, 'edit'])->name('settings');
    Route::post('/settings', [AdminSettingsController::class, 'update'])->name('settings.update');
    Route::post('/d365-settings', [D365SyncController::class, 'saveSettings'])->name('d365.settings.update');
    Route::post('/users', [AdminSettingsController::class, 'storeUser'])->name('users.store');
    Route::patch('/users/{user}', [AdminSettingsController::class, 'updateUser'])->name('users.update');
});

