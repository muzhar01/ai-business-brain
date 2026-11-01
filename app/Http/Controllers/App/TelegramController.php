<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\HandleTelegramUpdate;
use App\Models\Workspace;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    public function __invoke(Request $request, string $uuid)
    {
        $ws = Workspace::where('uuid', $uuid)->firstOrFail();
        abort_unless($ws->telegram_secret && hash_equals($ws->telegram_secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        if ($ws->allowsPublicChannel('telegram')) {
            HandleTelegramUpdate::dispatch($ws->id, $request->all());
        }

        return response()->json(['ok' => true]);
    }
}
