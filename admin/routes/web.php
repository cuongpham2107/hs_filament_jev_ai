<?php

use App\Http\Controllers\TraCuuStreamController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Khung chat tra cứu trong admin (widget TraCuuChat): chạy workflow Dify, trả về Server-Sent Events
Route::post('/admin/tra-cuu/stream', TraCuuStreamController::class)
    ->middleware(['auth', 'throttle:30,1'])
    ->name('tra-cuu.stream');
