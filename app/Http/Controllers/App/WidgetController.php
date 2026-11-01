<?php

namespace App\Http\Controllers\App;

use App\Brain\Brain;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Saas\Quota;
use App\Saas\QuotaExceeded;
use App\Support\Direction;
use App\Support\Locale;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Public website chat widget: <script src="/widget.js" data-key="wk_..."></script> */
class WidgetController extends Controller
{
    protected function workspace(string $key): Workspace
    {
        $ws = Workspace::where('widget_key', $key)->where('suspended', false)->firstOrFail();
        Quota::feature($ws, 'widget');
        abort_if($ws->hipaa(), 403, 'Public channels are disabled for this workspace.');
        Tenant::set($ws);

        return $ws;
    }

    public function script()
    {
        $body = view('widget.script')->render();
        $etag = '"'.substr(hash('sha256', $body), 0, 32).'"';
        $headers = ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=300, stale-while-revalidate=86400', 'ETag' => $etag, 'Cross-Origin-Resource-Policy' => 'cross-origin'];
        if (in_array($etag, array_map(fn ($t) => trim(preg_replace('/^W\//', '', trim($t))), explode(',', (string) request()->header('If-None-Match'))), true)) {
            return response('', 304, $headers);
        }

        return response($body, 200, $headers);
    }

    /** Widget language (ADR 0001): embed data-locale or the host page's html lang → workspace default → app fallback. */
    protected function locale(Request $request, Workspace $ws): string
    {
        return Locale::match($request->input('locale')) ?? Locale::match($ws->setting('language')) ?? Locale::fallback();
    }

    public function config(Request $request, string $key)
    {
        $ws = $this->workspace($key);
        $agent = Agent::where('widget_enabled', true)->first() ?? Agent::default();
        $locale = $this->locale($request, $ws);
        $t = fn (string $text, array $replace = []) => __($text, $replace, $locale);

        return response()->json([
            'name' => $ws->setting('business_name') ?: $ws->name,
            'greeting' => $agent?->widget_greeting ?: $t('Hi! How can I help you today?'),
            'color' => $agent?->widget_color ?: $ws->setting('brand_color', '#7c9cff'),
            'branding' => ! $ws->plan()->has('white_label'),
            'privacy' => $ws->setting('privacy_notice') ?: $t('Chats are stored to answer you and improve service. Don’t share sensitive personal data.'),
            'locale' => $locale,
            'lang' => Direction::lang($locale),
            'dir' => Direction::of($locale),
            'strings' => [
                'open' => $t('Chat with us'),
                'close' => $t('Close chat'),
                'placeholder' => $t('Type your message…'),
                'input' => $t('Your message'),
                'send' => $t('Send'),
                'powered' => $t('Powered by :name', ['name' => config('saas.name')]),
                'error' => $t('Sorry, something went wrong.'),
                'typing' => $t('Assistant is typing…'),
                'offline' => $t('Connection problem. Please try again.'),
                'retry' => $t('Retry'),
                'offline_wait' => $t('You are offline. Your message will be sent when you reconnect.'),
            ],
        ])->header('Access-Control-Allow-Origin', '*');
    }

    public function chat(Request $request, string $key, Brain $brain)
    {
        $ws = $this->workspace($key);
        $data = $request->validate([
            'message' => 'required|string|max:2000',
            'locale' => 'nullable|string|max:35',
            'visitor' => 'required|string|max:64',
            'conversation_id' => 'nullable|string|max:40',
        ]);

        $conversation = isset($data['conversation_id'])
            ? Conversation::where('uuid', $data['conversation_id'])->where('channel', 'widget')->where('external_ref', 'web:'.$data['visitor'])->first()
            : null;

        $agent = $conversation ? null : (Agent::where('widget_enabled', true)->first() ?? Agent::default());

        try {
            $result = $brain->ask($data['message'], $conversation, null, [
                'channel' => 'widget',
                'external_ref' => 'web:'.Str::limit($data['visitor'], 60, ''),
                'user_ref' => 'visitor '.substr($data['visitor'], 0, 8),
                'agent' => $agent,
            ]);
        } catch (QuotaExceeded) {
            return response()->json(['answer' => __('Sorry, our assistant is unavailable right now. Please contact us directly.', [], $this->locale($request, $ws))], 200)->header('Access-Control-Allow-Origin', '*');
        }

        return response()->json([
            'conversation_id' => $result['conversation']->uuid,
            'answer' => $result['message']->content,
        ])->header('Access-Control-Allow-Origin', '*');
    }

    public function preflight()
    {
        return response('', 204)->withHeaders([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept',
        ]);
    }
}
