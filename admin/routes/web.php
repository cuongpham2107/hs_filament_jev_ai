<?php

use App\Http\Controllers\TraCuuConversationController;
use App\Http\Controllers\TraCuuStreamController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::redirect('/admin', '/admin/tra-cuu');

// Khung chat tra cứu trong admin (widget TraCuuChat): chạy App\Ai\HsLookup, trả về Server-Sent Events
Route::post('/admin/tra-cuu/stream', TraCuuStreamController::class)
    ->middleware(['auth', 'throttle:30,1'])
    ->name('tra-cuu.stream');

Route::middleware('auth')->prefix('/admin/tra-cuu/conversations')->name('tra-cuu.conversations.')->group(function (): void {
    Route::get('/', [TraCuuConversationController::class, 'index'])->name('index');
    Route::get('/{conversationId}', [TraCuuConversationController::class, 'show'])->name('show');
    Route::delete('/{conversationId}', [TraCuuConversationController::class, 'destroy'])->name('destroy');
});
