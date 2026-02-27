<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

//Group name typebot
Route::group(['prefix' => 'typebot'], function () {
    Route::post('/monday-lead', [\App\Http\Controllers\Api\TypebotMondayController::class, 'upsert']);
});
