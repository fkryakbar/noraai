<?php

namespace App\Services;

use OpenAI as GlobalOpenAI;
use Telegram\Bot\Laravel\Facades\Telegram;

class OpenAi
{


    public static function completions($prompt, $chat_id, $user)
    {
        $client = GlobalOpenAI::client(env('OPEN_AI_TOKEN'));

        $messages = [
            [
                'role' => 'system',
                'content' => "You are an assistant who enjoys helping. You are an assistant who likes to assist with any tasks in a detailed, complete, and accurate manner."
            ],
            ...$prompt
        ];

        // dd($messages);
        // array_push($messages, $prompt);
        // Telegram::sendChatAction([
        //     'chat_id' => $chat_id,
        //     'action' => 'typing'
        // ]);
        $response = $client->chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => $messages
        ]);
        $user->total_token = $response->usage->totalTokens + $user->total_token;
        $user->token = $user->token - $response->usage->totalTokens;
        $user->save();



        return $response->choices[0]->message->content;
    }
}
