<?php

use App\Http\Controllers\SpreadSheetsController;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Route;
use Telegram\Bot\Laravel\Facades\Telegram;


Route::get('/webhook', function () {
    // Telegram::setWebhook(
    //     ['url' => 'https://siapeka.fkipulm.id']
    // );
    // Telegram::deleteWebhook();
    TelegramService::replyMessage();
});

Route::group(['prefix' => 'api'], function () {
    Route::post('/spreadsheets', [SpreadSheetsController::class, 'index']);
});
