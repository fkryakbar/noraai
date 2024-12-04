<?php

namespace App\Services;

use App\Models\LastUpdateId;
use App\Models\User;
use Telegram\Bot\Laravel\Facades\Telegram as FacadesTelegram;

class Telegram
{

    private static function getLastUpdateId()
    {
        $lastUpdate = LastUpdateId::first();
        if ($lastUpdate) {
            return $lastUpdate->last_update_id;
        }
        $lastUpdate = LastUpdateId::create([
            'last_update_id' => 298578495
        ]);

        return $lastUpdate->last_update_id;
    }

    private static function updateLastUpdateId($lastId)
    {
        $lastUpdate = LastUpdateId::first();
        if ($lastUpdate) {
            $lastUpdate->update([
                'last_update_id' => $lastId
            ]);
        }
    }

    private static function sendMessage($chat_id, $text)
    {
        FacadesTelegram::sendChatAction([
            'chat_id' => $chat_id,
            'action' => 'typing'
        ]);
        FacadesTelegram::sendMessage([
            'chat_id' => $chat_id,
            'text' => $text
        ]);
    }

    public static function escapeTelegramMarkdown($text)
    {
        $reserved = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        // $reserved = ['_', '*', '`', '['];
        // $reserved = ['.', '!'];
        foreach ($reserved as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }
        return $text;
    }

    public static function replyMessage()
    {
        $updates = collect(FacadesTelegram::getUpdates(['offset' => self::getLastUpdateId() + 1]));
        // dd($updates);
        if ($updates->count() > 0) {
            $updates->each(function ($update) {
                $chat_id = $update['message']['from']['id'];
                $message = isset($update['message']['text']) ? $update['message']['text'] : '';
                $user = User::updateOrCreate([
                    'telegram_id' => $update['message']['from']['id']
                ], [
                    'name' => $update['message']['from']['first_name']
                ]);

                if ($user->is_verified) {
                    if ($message === '/usage') {
                        self::sendMessage($chat_id, 'Name : ' . $user->name);
                        self::sendMessage($chat_id, 'Token left : ' . $user->token);
                        self::sendMessage($chat_id, 'Token Used : ' . $user->total_token);
                    } else {
                        if ($user->token > 0) {
                            $prompt = [];
                            if (isset($update['message']['reply_to_message'])) {
                                if ($update['message']['reply_to_message']['from']['is_bot']) {
                                    array_push($prompt, [
                                        'role' => 'system',
                                        'content' => $update['message']['reply_to_message']['text']
                                    ]);
                                } else {
                                    array_push($prompt, [
                                        'role' => 'user',
                                        'content' => $update['message']['reply_to_message']['text']
                                    ]);
                                }
                            }
                            array_push($prompt, [
                                'role' => 'user',
                                'content' => $message
                            ]);
                            FacadesTelegram::sendChatAction([
                                'chat_id' => $chat_id,
                                'action' => 'typing'
                            ]);
                            $response = OpenAi::completions($prompt, $chat_id, $user);
                            FacadesTelegram::sendChatAction([
                                'chat_id' => $chat_id,
                                'action' => 'typing'
                            ]);
                            FacadesTelegram::sendMessage([
                                'chat_id' => $chat_id,
                                'text' => self::parse($response),
                                'parse_mode' => 'HTML'
                            ]);
                        } else {
                            self::sendMessage($chat_id, 'You do not have any tokens left.');
                        }
                    }
                } else {
                    self::sendMessage($chat_id, 'Your account has not been verified. Please request account verification from my creator.');
                }
            });

            $lastUpdate = $updates->last();
            self::updateLastUpdateId($lastUpdate['update_id']);
        }
    }
    public static function parse($markdown)
    {
        // Escape semua <, >, dan & yang tidak bagian dari tag atau entitas
        $markdown = htmlspecialchars($markdown, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Ganti elemen Markdown dengan format HTML yang diminta
        $patterns = [
            '/^# (.*?)$/m',                             // H1 (# heading)
            '/^## (.*?)$/m',                            // H2 (## heading)
            '/^### (.*?)$/m',                           // H3 (### heading)
            '/\*\*(.*?)\*\*/s',                        // Bold (**bold**)
            '/\*(.*?)\*/s',                            // Italic (*italic*)
            '/~~(.*?)~~/s',                            // Strikethrough (~~text~~)
            '/__([^_]+)__/s',                          // Underline (__underline__)
            '/\|\|([^|]+)\|\|/s',                      // Spoiler (||spoiler||)
            '/\[(.*?)\]\((https?:\/\/.*?)\)/s',        // Inline URL ([text](url))
            '/\[(.*?)\]\(tg:\/\/user\?id=(\d+)\)/s',   // Inline mention ([text](tg://user?id=123))
            '/`([^`]+)`/s',                            // Inline code (`code`)
            '/```([a-z]*)\n(.*?)```/s',                // Code block (```language\ncode```)
            '/^> (.+)/m',                              // Blockquote (> quote)
        ];

        $replacements = [
            '<b>\1</b>',                                                    // H1 -> Bold
            '<b>\1</b>',                                                    // H2 -> Bold
            '<b>\1</b>',
            '<b>\1</b>',                                                    // Bold
            '<i>\1</i>',                                                    // Italic
            '<s>\1</s>',                                                    // Strikethrough
            '<u>\1</u>',                                                    // Underline
            '<span class="tg-spoiler">\1</span>',                           // Spoiler
            '<a href="\2">\1</a>',                                          // Inline URL
            '<a href="tg://user?id=\2">\1</a>',                             // Inline mention
            '<code>\1</code>',                                              // Inline code
            '<pre><code>\2</code></pre>',                                   // Code block dengan <pre><code>
            '<blockquote>\1</blockquote>',                                  // Blockquote
        ];

        // Escape karakter khusus di dalam blok kode
        $markdown = preg_replace_callback('/```([a-z]*)\n(.*?)```/s', function ($matches) {
            $codeLanguage = $matches[1] ? strtolower($matches[1]) : '';
            $codeContent = htmlspecialchars($matches[2], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return "<pre><code class=\"$codeLanguage\">$codeContent</code></pre>";
        }, $markdown);

        // Lakukan pencocokan dan penggantian
        $html = preg_replace($patterns, $replacements, $markdown);

        return $html;
    }
}
