<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Support\Facades\Http;

class SlackController extends Controller
{
     public function handle(Request $request)
    {
        try {
           // \Log::info('working');
    
            $this->verifySlackSignature($request);
    
            $payload = json_decode($request->payload, true);
    
            \Log::info('Slack payload', ['payload' => $payload]);
    
            if ($payload['type'] === 'message_action' &&
                $payload['callback_id'] === 'make_task_from_message') {
    
                return $this->makeTaskFromSlack($payload);
            }
    
            return response()->json(['ok' => true]);
    
        } catch (\Throwable $e) {
            \Log::error('Slack handle error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
    
            // Always return JSON so Slack is happy
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Something went wrong: ' . $e->getMessage()
            ]);
        }
    }


    private function verifySlackSignature(Request $request)
    {
        $timestamp = $request->header('X-Slack-Request-Timestamp');
        $signature = $request->header('X-Slack-Signature');

        if (abs(time() - $timestamp) > 300) {
            abort(403, 'Slack timeout');
        }

        $sigBase = 'v0:' . $timestamp . ':' . $request->getContent();
        $mySig = 'v0=' . hash_hmac(
            'sha256',
            $sigBase,
            config('services.slack.signing_secret')
        );

        if (!hash_equals($mySig, $signature)) {
            abort(403, 'Invalid Slack signature');
        }
    }


    private function makeTaskFromSlack(array $payload)
    {
        try {
            $messageText = $payload['message']['text'] ?? 'Slack Task';
            $userSlackId = $payload['user']['id'];
    
          //  \Log::info('Mapping Slack user', ['slack_id' => $userSlackId]);
            
            /*
    
            $user = User::where('slack_id', $userSlackId)->first();
    
            if (!$user) {
                \Log::info('slack user not found');
                return response()->json([
                    'response_type' => 'ephemeral',
                    'text' => '❌ Slack user not linked with system.'
                ]);
            } */
    
            $task = ProjectTask::create([
                'project_id' => 12, // or auto detect
                'created_by' => 1,
                'task_title' => str($messageText)->limit(255),
                'task_description' => $messageText,
                'task_status' => 'Backlog',
                'priority' => 'Normal',
            ]);
    
          //  \Log::info('Task created', ['task_id' => $task->id]);
            
            $responseUrl = $payload['response_url'];
Http::post($responseUrl, [
    'response_type' => 'ephemeral',
    'text' => "✅ Task created: *{$task->task_title}*"
]);
    
            return response()->json(['ok' => true]);
    
        } catch (\Throwable $e) {
            \Log::error('Slack makeTaskFromSlack error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
    
            return response()->json([
                'response_type' => 'ephemeral',
                'text' => '❌ Failed to create task: ' . $e->getMessage()
            ]);
        }
    }
}
