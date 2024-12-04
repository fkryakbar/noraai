<?php

namespace App\Console\Commands;

use App\Services\Telegram;
use Illuminate\Console\Command;

class StartBot extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bot:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start Telegram Bot';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Telegram Bot is Running in the background.');
        while (true) {
            Telegram::replyMessage();
            sleep(1);
        }
    }
}
