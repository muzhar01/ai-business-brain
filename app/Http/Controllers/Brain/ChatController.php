<?php

namespace App\Http\Controllers\Brain;

use App\Brain\Brain;
use App\Brain\Exceptions\BrainException;
use App\Brain\LlmManager;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Saas\Quota;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function chat(Request $request, Brain $brain, LlmManager $llm)
    {
        [$data, $conversation, $options] = $this->prepare($request, $llm);

        try {
            $result = $brain->ask($data['message'], $conversation, $data['provider'] ?? null, $options);
        } catch (BrainException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($this->payload($result));
    }

    /**
     * Server-sent events: `delta` {text} as tokens arrive, `tool` {name} when a tool runs,
     * then `done` with the same body as POST /chat, or `error` {message}.
     */
    public function stream(Request $request, Brain $brain, LlmManager $llm)
    {
        [$data, $conversation, $options] = $this->prepare($request, $llm);
        $ws = Tenant::current();
        if ($ws) {
            Quota::ensure($ws, 'messages'); // fail with a proper 402 before headers are sent
        }

        // The body is sent after middleware has finished (and reset the tenant), so restore it here.
        return response()->stream(function () use ($brain, $data, $conversation, $options, $ws) {
            $ws && Tenant::set($ws);
            $send = function (string $event, array $payload) {
                echo "event: {$event}\ndata: ".json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };
            $options['on_event'] = $send;

            try {
                $send('done', $this->payload($brain->ask($data['message'], $conversation, $data['provider'] ?? null, $options)));
            } catch (\Throwable $e) {
                report($e);
                $send('error', ['message' => $e instanceof BrainException ? $e->getMessage() : 'Something went wrong.']);
            } finally {
                Tenant::forget();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no', // nginx: don't buffer
            'Connection' => 'keep-alive',
        ]);
    }

    protected function payload(array $result): array
    {
        return [
            'conversation_id' => $result['conversation']->uuid,
            'message_id' => $result['message']->id,
            'answer' => $result['message']->content,
            'provider' => $result['message']->provider,
            'model' => $result['message']->model,
            'sources' => $result['sources'],
            'tool_runs' => $result['tool_runs'],
            'usage' => $result['usage'],
        ];
    }

    protected function prepare(Request $request, LlmManager $llm): array
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:20000'],
            'conversation_id' => ['nullable', 'string', 'exists:brain_conversations,uuid'],
            'provider' => ['nullable', 'string', 'in:'.implode(',', $llm->names())],
            'model' => ['nullable', 'string', 'max:120'],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:200000'],
            'collection' => ['nullable', 'string', 'max:100'],
            'tools' => ['nullable'],
            'user_ref' => ['nullable', 'string', 'max:191'],
            'agent_id' => ['nullable', 'integer'],
        ]);

        $conversation = isset($data['conversation_id'])
            ? Conversation::where('uuid', $data['conversation_id'])->first()
            : null;

        $options = collect($data)->only(['model', 'temperature', 'max_tokens', 'collection', 'user_ref'])->filter(fn ($v) => $v !== null)->all();
        $options['channel'] = $request->attributes->has('api_token') ? 'api' : 'app';
        $options['user_id'] = $request->user()?->id;
        if (! empty($data['agent_id'])) {
            $options['agent'] = Agent::findOrFail($data['agent_id']);
        }
        if (array_key_exists('tools', $data)) {
            $options['tools'] = $data['tools'] === false || $data['tools'] === 'false' ? false : (array) $data['tools'];
        }

        return [$data, $conversation, $options];
    }

    public function conversations(Request $request)
    {
        return Conversation::query()
            ->when($request->query('user_ref'), fn ($q, $u) => $q->where('user_ref', $u))
            ->when($request->query('channel'), fn ($q, $c) => $q->where('channel', $c))
            ->when($request->query('q'), fn ($q, $term) => $q->where('title', 'like', "%{$term}%"))
            ->latest('updated_at')->paginate(30);
    }

    public function show(Conversation $conversation)
    {
        $this->logPhiAccess('phi.conversation_viewed', $conversation);

        return [
            'conversation' => $conversation,
            'messages' => $conversation->messages()->whereIn('role', ['user', 'assistant'])
                ->where(fn ($q) => $q->whereNotNull('content')->where('content', '!=', ''))
                ->get(['id', 'role', 'content', 'sources', 'provider', 'model', 'input_tokens', 'output_tokens', 'rating', 'created_at']),
        ];
    }

    public function export(Conversation $conversation, Request $request)
    {
        $this->logPhiAccess('phi.conversation_exported', $conversation);
        $messages = $conversation->messages()->whereIn('role', ['user', 'assistant'])->whereNotNull('content')->where('content', '!=', '')->get();

        if ($request->query('format') === 'json') {
            return response()->json(['conversation' => $conversation, 'messages' => $messages]);
        }

        $md = '# '.($conversation->title ?: 'Conversation')."\n\n";
        foreach ($messages as $m) {
            $md .= '**'.($m->role === 'user' ? 'You' : 'Brain').'** · '.$m->created_at->toDateTimeString()."\n\n".$m->content."\n\n";
        }

        return response($md, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="conversation-'.$conversation->uuid.'.md"',
        ]);
    }

    public function rate(Request $request, Message $message)
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'in:-1,0,1']]);
        abort_unless($message->role === 'assistant', 422);
        $message->update(['rating' => $data['rating'] ?: null]);

        return ['ok' => true];
    }

    public function agents()
    {
        return Agent::orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'description', 'provider', 'model', 'is_default']);
    }

    public function destroy(Conversation $conversation)
    {
        $conversation->delete();

        return response()->noContent();
    }

    public function providers(LlmManager $llm)
    {
        return [
            'default' => $llm->defaultProvider(),
            'embeddings' => config('brain.embeddings.provider'),
            'providers' => $llm->available(),
        ];
    }

    /** HIPAA §164.312(b): record every access to conversation content. */
    protected function logPhiAccess(string $action, Conversation $conversation): void
    {
        if (Tenant::current()?->hipaa()) {
            AuditLog::record($action, $conversation);
        }
    }
}
