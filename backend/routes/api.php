<?php

use App\Http\Controllers\Api\Admin\TechnicianController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\TicketCommentController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\TicketEventController;
use App\Http\Controllers\Api\TicketWorkflowController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'API SupportOps disponible',
    ]);
});

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('v1')->name('tickets-api.')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
        Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->whereNumber('ticket')->name('tickets.show');
        Route::patch('/tickets/{ticket}', [TicketWorkflowController::class, 'update'])->whereNumber('ticket')->name('tickets.update');
        Route::patch('/tickets/{ticket}/assignment', [TicketWorkflowController::class, 'assign'])->whereNumber('ticket')->name('tickets.assign');
        Route::patch('/tickets/{ticket}/status', [TicketWorkflowController::class, 'status'])->whereNumber('ticket')->name('tickets.status');
        Route::post('/tickets/{ticket}/comments', [TicketCommentController::class, 'store'])->whereNumber('ticket')->name('tickets.comments.store');
        Route::get('/tickets/{ticket}/comments', [TicketCommentController::class, 'index'])->whereNumber('ticket')->name('tickets.comments.index');
        Route::get('/tickets/{ticket}/events', [TicketEventController::class, 'index'])->whereNumber('ticket')->name('tickets.events.index');
    });

    Route::prefix('v1/admin')->name('admin.')->group(function () {
        Route::get('/technicians', [TechnicianController::class, 'index'])->name('technicians.index');
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
    });
});
