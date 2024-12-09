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
            $prompt = ' 
                        You are a helper that assists in analyzing data. You will be given data in the form of CSV, then analyze it according to the analysis I request.

                        ***Return only a JSON object*** with the following two properties:

                        - "message": write an explanation regarding the data I sent and an explanation of what I need.
                        - "transformedData": If I ask you to modify the data, fill this field with the data you have transformed. ***Format data only in JSON*** if I do not instruct you, then just fill it with null.

                        Both JSON properties must always be present.

                        Do not include any additional text or explanations outside the JSON object.

                        DATA:
                        ' . $request->data . '
                        Instructions:    
                        ' . $request->text . '
                    
                    ';
            $messages = [
                [
                    'role' => 'user',
                    'content' => trim($prompt)
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
                'response' => json_decode($this->cleanMarkdownJson($response->choices[0]->message->content), true)
            ]);
        }

        return response([
            'code' => 422,
            'response' => 'Your account is not yet usable.'
        ], 422);
    }
    private function cleanMarkdownJson($input)
    {
        // Hapus kata ```json di awal dan ``` di akhir
        $cleaned = str_replace(['```json', '```'], '', $input);

        // Hilangkan spasi tambahan di awal dan akhir string
        return trim($cleaned);
    }
}
