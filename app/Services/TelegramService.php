<?php

namespace App\Services;

use App\Models\LastUpdateId;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Http;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramService
{
    private static function getLastUpdateId()
    {
        $lastUpdate = LastUpdateId::first();

        if (!$lastUpdate) {
            $lastUpdate = LastUpdateId::create(['last_update_id' => 298578495]);
        }

        return $lastUpdate->last_update_id;
    }

    private static function updateLastUpdateId($lastId)
    {
        LastUpdateId::first()->update(['last_update_id' => $lastId]);
    }

    private static function sendMessage($chatId, $text, $parseMode = null)
    {
        Telegram::sendChatAction(['chat_id' => $chatId, 'action' => 'typing']);
        Telegram::sendMessage(['chat_id' => $chatId, 'text' => $text, 'parse_mode' => $parseMode]);
    }

    public static function escapeTelegramMarkdown($text)
    {
        $reservedChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        return str_replace($reservedChars, array_map(fn($char) => '\\' . $char, $reservedChars), $text);
    }

    public static function replyMessage()
    {
        $updates = collect(Telegram::getUpdates(['offset' => self::getLastUpdateId() + 1]));

        if ($updates->isEmpty()) return;

        $updates->each(function ($update) {
            $chatId = $update['message']['from']['id'];
            $message = isset($update['message']['text']) ? $update['message']['text'] : null;
            $photo = isset($update['message']['photo']) ? collect($update['message']['photo'])->last() : null;
            $caption = isset($update['message']['caption']) ? $update['message']['caption'] : null;

            $user = self::findOrCreateUser($update['message']['from']);

            if (!$user->is_verified) {
                self::sendMessage($chatId, 'Your account has not been verified. Please request account verification from my creator.');
                return;
            }
            if ($message) {
                self::handleUserMessageGemini($chatId, $message, $user, $update);
            }
            if ($photo) {
                self::handleUserPhotoGemini($chatId, $photo, $user, $update, $caption);
            }
        });

        self::updateLastUpdateId($updates->last()['update_id']);
    }

    private static function findOrCreateUser($from)
    {
        return User::updateOrCreate(
            ['telegram_id' => $from['id']],
            ['name' => $from['first_name']]
        );
    }

    private static function convertFileToBase64($url)
    {
        $fileContent = file_get_contents($url);

        if ($fileContent === false) {
            throw new Exception("Failed to fetch the file from the URL: $url");
        }

        return base64_encode($fileContent);
    }

    // Fungsi baru untuk menggunakan Gemini API untuk menangani foto
    private static function handleUserPhotoGemini($chatId, $photo, $user, $update, $caption)
    {
        $file = Telegram::getFile(['file_id' => $photo['file_id']]);
        $linkPhoto = 'https://api.telegram.org/file/bot' . env('TELEGRAM_BOT_TOKEN') . '/' . $file['file_path'];

        $role = 'user';
        $content = [
            [
                'type' => 'text',
                'text' => $caption ?? 'Jelaskan apa yang ada digambar'
            ],
            [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $linkPhoto
                ]
            ]
        ];

        $prompt[] = compact('role', 'content');

        $response = self::requestGemini($prompt); // Menggunakan Gemini API
        self::sendMessage($chatId, self::parse($response), 'HTML');
    }

    // Fungsi baru untuk menggunakan Gemini API untuk menangani pesan teks
    private static function handleUserMessageGemini($chatId, $message, $user, $update)
    {
        if ($message === '/usage') {
            self::sendMessage($chatId, "Name: {$user->name}");
            self::sendMessage($chatId, "Token left: {$user->token}");
            self::sendMessage($chatId, "Token Used: {$user->total_token}");
            return;
        }

        if ($user->token <= 0) {
            self::sendMessage($chatId, 'You do not have any tokens left.');
            return;
        }

        $prompt = self::buildPromptGemini($message, $update); // Gunakan Gemini prompt
        $response = self::requestGemini($prompt); // Menggunakan Gemini API
        self::sendMessage($chatId, self::parse($response), 'HTML');
    }

    // Fungsi untuk membangun prompt Gemini
    private static function buildPromptGemini($message, $update)
    {
        $prompt = [];

        if (!empty($update['message']['reply_to_message'])) {
            $role = $update['message']['reply_to_message']['from']['is_bot'] ? 'system' : 'user';
            $content = $update['message']['reply_to_message']['text'];
            $prompt[] = compact('role', 'content');
        }

        $prompt[] = ['role' => 'user', 'content' => $message];
        return $prompt;
    }

    // Fungsi untuk melakukan request ke Gemini API
    private static function requestGemini($prompt)
    {
        $apiKey = env('GEMINI_API_KEY');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key={$apiKey}";

        $response = Http::post($url, [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => $prompt
                        ]
                    ]
                ]
            ]
        ]);

        if ($response->failed()) {
            throw new Exception("Failed to connect to Gemini API: " . $response->body());
        }

        return $response->json();
    }

    public static function parse($markdown)
    {
        $markdown = htmlspecialchars($markdown, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $patterns = [
            '/^# (.*?)$/m',                              // H1 (# heading)
            '/^## (.*?)$/m',                             // H2 (## heading)
            '/^### (.*?)$/m',                            // H3 (### heading)
            '/\*\*(.*?)\*\*/s',                          // Bold (**bold**)
            '/\*(.*?)\*/s',                              // Italic (*italic*)
            '/~~(.*?)~~/s',                              // Strikethrough (~~text~~)
            '/__([^_]+)__/s',                            // Underline (__underline__)
            '/\|\|([^|]+)\|\|/s',                        // Spoiler (||spoiler||)
            '/\[(.*?)\]\((https?:\/\/.*?)\)/s',          // Inline URL ([text](url))
            '/\[(.*?)\]\(tg:\/\/user\?id=(\d+)\)/s',     // Inline mention ([text](tg://user?id=123))
            '/`([^`]+)`/s',                              // Inline code (`code`)
            '/```([a-z]*)\n(.*?)```/s',                  // Code block (```language\ncode```)
            '/^> (.+)/m',                                // Blockquote (> quote)
        ];

        $replacements = [
            '<b>\1</b>',                                  // H1 -> Bold
            '<b>\1</b>',                                  // H2 -> Bold
            '<b>\1</b>',                                  // H3 -> Bold
            '<b>\1</b>',                                  // Bold
            '<i>\1</i>',                                  // Italic
            '<s>\1</s>',                                  // Strikethrough
            '<u>\1</u>',                                  // Underline
            '<span class="tg-spoiler">\1</span>',         // Spoiler
            '<a href="\2">\1</a>',                        // Inline URL
            '<a href="tg://user?id=\2">\1</a>',           // Inline mention
            '<code>\1</code>',                            // Inline code
            '<pre><code>\2</code></pre>',                 // Code block
            '<blockquote>\1</blockquote>',                // Blockquote
        ];

        $markdown = preg_replace_callback('/```([a-z]*)\n(.*?)```/s', function ($matches) {
            $lang = strtolower($matches[1] ?? '');
            $content = htmlspecialchars($matches[2], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return "<pre><code class=\"$lang\">$content</code></pre>";
        }, $markdown);

        return preg_replace($patterns, $replacements, $markdown);
    }
}
