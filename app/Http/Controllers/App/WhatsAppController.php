<?php

namespace App\Http\Controllers\App;

use App\Channels\WhatsApp;
use App\Http\Controllers\Controller;
use App\Jobs\HandleWhatsAppUpdate;
use App\Models\Workspace;
use Illuminate\Http\Request;

class WhatsAppController extends Controller
{
    /** Meta webhook verification handshake. */
    public function verify(Request $request, string $uuid)
    {
        $ws = Workspace::where('uuid', $uuid)->firstOrFail();
        $expected = $ws->whatsapp['verify_token'] ?? null;

        abort_unless($request->query('hub_mode') === 'subscribe' && $expected && hash_equals($expected, (string) $request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, string $uuid)
    {
        $ws = Workspace::where('uuid', $uuid)->firstOrFail();
        abort_unless(WhatsApp::validSignature($request->getContent(), $request->header('X-Hub-Signature-256'), $ws->whatsapp['app_secret'] ?? null), 403);

        if ($ws->allowsPublicChannel('whatsapp')) {
            HandleWhatsAppUpdate::dispatch($ws->id, $request->all());
        }

        return response()->json(['ok' => true]);
    }
}
