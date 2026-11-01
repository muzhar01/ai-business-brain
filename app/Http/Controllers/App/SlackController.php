<?php

namespace App\Http\Controllers\App;

use App\Channels\Slack;
use App\Http\Controllers\Controller;
use App\Jobs\HandleSlackEvent;
use App\Models\Workspace;
use Illuminate\Http\Request;

class SlackController extends Controller
{
    public function __invoke(Request $request, string $uuid)
    {
        $ws = Workspace::where('uuid', $uuid)->firstOrFail();
        abort_unless(Slack::validSignature(
            $request->getContent(),
            $request->header('X-Slack-Request-Timestamp'),
            $request->header('X-Slack-Signature'),
            $ws->slack['signing_secret'] ?? null,
        ), 403);

        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        // Slack retries when we take > 3 s; the first delivery is already queued.
        if ($request->hasHeader('X-Slack-Retry-Num')) {
            return response()->noContent();
        }

        if ($request->input('type') === 'event_callback' && $ws->allowsPublicChannel('slack')) {
            HandleSlackEvent::dispatch($ws->id, (array) $request->input('event'));
        }

        return response()->noContent();
    }
}
