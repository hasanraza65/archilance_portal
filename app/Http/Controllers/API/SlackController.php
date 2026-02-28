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
                    
                return $this->openTaskModal($payload);
    
               // return $this->makeTaskFromSlack($payload);
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
    
    
   private function openTaskModal(array $payload)
{
    $triggerId = $payload['trigger_id'];
    $messageText = $payload['message']['text'] ?? '';

    $modal = [
        "trigger_id" => $triggerId,
        "view" => [
            "type" => "modal",
            "callback_id" => "task_modal_submit",
            "private_metadata" => json_encode(['job_id' => null]), // initial empty
            "title" => [
                "type" => "plain_text",
                "text" => "Create Task"
            ],
            "submit" => [
                "type" => "plain_text",
                "text" => "Create"
            ],
            "close" => [
                "type" => "plain_text",
                "text" => "Cancel"
            ],
            "blocks" => [
                [
                    "type" => "input",
                    "block_id" => "job_block",
                    "label" => [
                        "type" => "plain_text",
                        "text" => "Select Job"
                    ],
                    "element" => [
                        "type" => "external_select",
                        "action_id" => "job_select",
                        "placeholder" => [
                            "type" => "plain_text",
                            "text" => "Choose job..."
                        ]
                    ]
                ],
                [
                    "type" => "input",
                    "optional" => true,
                    "block_id" => "parent_task_block",
                    "label" => [
                        "type" => "plain_text",
                        "text" => "Parent Task (optional)"
                    ],
                    "element" => [
                        "type" => "external_select",
                        "action_id" => "task_select",
                        "placeholder" => [
                            "type" => "plain_text",
                            "text" => "Choose parent task..."
                        ]
                    ]
                ],
                [
                    "type" => "input",
                    "block_id" => "title_block",
                    "label" => [
                        "type" => "plain_text",
                        "text" => "Task Title"
                    ],
                    "element" => [
                        "type" => "plain_text_input",
                        "action_id" => "task_title",
                        "initial_value" => $messageText
                    ]
                ]
            ]
        ]
    ];

    Http::withToken(config('services.slack.bot_token'))
        ->post("https://slack.com/api/views.open", $modal);

    return response()->json(['ok' => true]);
}



public function handleSelectMenu(Request $request)
{
    $payload = json_decode($request->input('payload'), true);
    $actionId = $payload['actions'][0]['action_id'] ?? null;

    if ($actionId === 'job_select') {
        $selectedJobId = $payload['actions'][0]['selected_option']['value'] ?? null;

        // Update modal's private_metadata with the selected job
        $metadata = ['job_id' => $selectedJobId];

        // Call Slack API to update the modal
        Http::withToken(config('services.slack.bot_token'))
            ->post('https://slack.com/api/views.update', [
                'view_id' => $payload['view']['id'],
                'hash' => $payload['view']['hash'],
                'view' => [
                    'type' => 'modal',
                    'callback_id' => $payload['view']['callback_id'],
                    'private_metadata' => json_encode($metadata),
                    'title' => $payload['view']['title'],
                    'submit' => $payload['view']['submit'],
                    'close' => $payload['view']['close'],
                    'blocks' => $payload['view']['blocks'],
                ]
            ]);
    }

    return response()->json(['ok' => true]);
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
    
    public function jobsList(Request $request)
    {
        $payload = json_decode($request->input('payload'), true);
    
        $search = $payload['value'] ?? '';
    
        $projects = Project::query()
            ->when($search, function ($q) use ($search) {
                $q->where('project_name', 'like', "%{$search}%");
            })
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get();
    
        $options = $projects->map(function($p){
            return [
                "text" => [
                    "type" => "plain_text",
                    "text" => substr($p->project_name ?? "Project #".$p->id, 0, 70)
                ],
                "value" => (string) $p->id
            ];
        })->values()->toArray();
    
        return response()->json([
            "options" => $options
        ]);
    }


    public function tasksList(Request $request)
    {
        $payload = json_decode($request->input('payload'), true);

        // get selected job_id
        $jobId = $payload['view']['state']['values']['job_block']['job_select']['selected_option']['value'] ?? null;

        $query = ProjectTask::query();

        if ($jobId) {
            $query->where('project_id', $jobId);
        }

        $tasks = $query->orderBy('id', 'desc')->limit(50)->get();

        $options = $tasks->map(function ($t) {
            return [
                "text" => [
                    "type" => "plain_text",
                    "text" => $t->task_title
                ],
                "value" => (string) $t->id
            ];
        });

        return response()->json([
            "options" => $options
        ]);
    }
    
    
    public function optionsLoader(Request $request)
{
    $payload = json_decode($request->input('payload'), true);

    $actionId = $payload['action_id'] ?? '';
    $search   = $payload['value'] ?? '';

    // ---------------- JOB DROPDOWN ----------------
    if ($actionId === "job_select") {

        $projects = Project::query()
            ->when($search, function ($q) use ($search) {
                $q->where('project_name', 'like', "%{$search}%");
            })
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get();

        $options = $projects->map(function($p){
            return [
                "text" => [
                    "type" => "plain_text",
                    "text" => substr($p->project_name ?? "Project #".$p->id, 0, 70)
                ],
                "value" => (string) $p->id
            ];
        })->values()->toArray();

        return response()->json(["options" => $options]);
    }

    // ---------------- TASK DROPDOWN ----------------
    if ($actionId === "task_select") {

        $jobId = $payload['view']['state']['values']['job_block']['job_select']['selected_option']['value'] ?? null;
        
        \Log::info('job id: '.$jobId);
        \Log::info(json_encode($payload));

        $tasks = ProjectTask::query()
            ->when($jobId, function ($q) use ($jobId) {
                $q->where('project_id', $jobId);
            })
            ->when($search, function ($q) use ($search) {
                $q->where('task_title', 'like', "%{$search}%");
            })
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get();

        $options = $tasks->map(function($t){
            return [
                "text" => [
                    "type" => "plain_text",
                    "text" => substr($t->task_title ?? "Task #".$t->id, 0, 70)
                ],
                "value" => (string) $t->id
            ];
        })->values()->toArray();

        return response()->json(["options" => $options]);
    }

    // fallback
    return response()->json(["options" => []]);
}

}
