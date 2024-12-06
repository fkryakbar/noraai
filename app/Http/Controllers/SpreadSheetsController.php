<?php

namespace App\Http\Controllers;

use App\Models\AuthSpreadSheets;
use Carbon\Carbon;
use Illuminate\Http\Request;
use OpenAI;

class SpreadSheetsController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'email' => 'required',
            'data' => 'required',
            'text' => 'required'
        ]);

        $user = AuthSpreadSheets::updateOrCreate([
            'email' => $request->email
        ]);


        $validUntil = Carbon::parse($user->valid_until);

        if ($user->is_authorized && $validUntil->isFuture() && $user->token_left > 0) {
            $client = OpenAI::client(env('OPEN_AI_TOKEN'));

            $messages = [
                [
                    'role' => 'user',
                    'content' => $request->data . ' 
                        dari data diatas, silahkan kamu jelaskan kepada saya tentang : ' . $request->text
                ],

            ];

            $response = $client->chat()->create([
                'model' => 'o1-mini',
                'messages' => $messages
            ]);

            $user->total_token = $response->usage->totalTokens + $user->total_token;
            $user->token_left = $user->token_left - $response->usage->totalTokens;
            $user->save();

            return response([
                'code' => 200,
                'response' => $response->choices[0]->message->content
            ]);
        }

        return response([
            'code' => 422,
            'response' => 'Your account is not yet usable.'
        ], 422);
    }
}
