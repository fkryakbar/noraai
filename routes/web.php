<?php

use App\Services\Telegram;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    Telegram::replyMessage();
});
