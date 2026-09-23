<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/password/reset', [PasswordResetController::class, 'showLinkRequestForm'])->name('password.request');
    Route::post('/password/email', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/password/reset/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
    Route::post('/password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:6,1')->name('password.update');
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/folders/create', [FolderController::class, 'showCreateForm'])->name('folders.create');
    Route::post('/folders/create', [FolderController::class, 'create']);

    Route::prefix('/folders/{folder}')->middleware('can:view,folder')->scopeBindings()->group(function () {
        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::get('/edit', [FolderController::class, 'showEditForm'])->name('folders.edit');
        Route::post('/edit', [FolderController::class, 'edit']);
        Route::get('/delete', [FolderController::class, 'showDeleteForm'])->name('folders.delete');
        Route::post('/delete', [FolderController::class, 'delete']);
        Route::get('/tasks/create', [TaskController::class, 'showCreateForm'])->name('tasks.create');
        Route::post('/tasks/create', [TaskController::class, 'create']);
        Route::get('/tasks/{task}/edit', [TaskController::class, 'showEditForm'])->name('tasks.edit');
        Route::post('/tasks/{task}/edit', [TaskController::class, 'edit']);
        Route::get('/tasks/{task}/delete', [TaskController::class, 'showDeleteForm'])->name('tasks.delete');
        Route::post('/tasks/{task}/delete', [TaskController::class, 'delete']);
    });
});
