<?php

use App\Http\Controllers\Api\TariffController;
use App\Http\Middleware\EnsureHsApiToken;
use Illuminate\Support\Facades\Route;

Route::prefix('hs')->middleware(EnsureHsApiToken::class)->controller(TariffController::class)->group(function () {
    Route::get('muc-luc', 'catalog');
    Route::get('quy-tac', 'rules');
    Route::get('nhom/{code}', 'heading')->where('code', '\d{4}');
    Route::get('thue/{code}', 'tax')->where('code', '\d{8}');
});
