<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\AuditLog;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Saas\Quota;
use App\Security\UrlGuard;
use App\Tenancy\Tenant;
use App\Webhooks\Events;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WebhookController extends Controller
{
    public function index()
    {
        return view('app.settings.webhooks', [
            'endpoints' => WebhookEndpoint::latest()->get(),
            'deliveries' => WebhookDelivery::with('endpoint')->latest('id')->limit(30)->get(),
            'events' => Events::ALL,
        ]);
    }

    public function store(Request $request)
    {
        Quota::feature(Tenant::current(), 'webhooks');
        $data = $request->validate(['url' => 'required|url|max:500|starts_with:https://,http://', 'events' => 'required|array|min:1', 'events.*' => 'in:*,'.implode(',', Events::ALL)]);
        try {
            UrlGuard::assertPublic($data['url']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['url' => $e->getMessage()]);
        }
        $secret = 'whsec_'.Str::random(40);
        $endpoint = WebhookEndpoint::create(['url' => $data['url'], 'events' => array_values($data['events']), 'secret' => $secret]);
        Events::forget(Tenant::id());
        AuditLog::record('webhook.created', $endpoint, ['url' => $data['url']]);

        return back()->with('secret', $secret);
    }

    public function toggle(WebhookEndpoint $endpoint)
    {
        $endpoint->update(['active' => ! $endpoint->active, 'failures' => 0]);
        Events::forget(Tenant::id());

        return back();
    }

    public function destroy(WebhookEndpoint $endpoint)
    {
        AuditLog::record('webhook.deleted', $endpoint, ['url' => $endpoint->url]);
        $endpoint->delete();
        Events::forget(Tenant::id());

        return back()->with('status', __('Endpoint deleted.'));
    }

    public function ping()
    {
        Events::dispatch('lead.created', ['id' => 0, 'name' => 'Test lead', 'email' => 'test@example.com', 'test' => true]);

        return back()->with('status', __('Test event sent to subscribed endpoints.'));
    }

    public function retry(WebhookDelivery $delivery)
    {
        $delivery->update(['status' => 'pending']);
        DeliverWebhook::dispatch(Tenant::id(), $delivery->id)->onQueue('webhooks');

        return back()->with('status', __('Redelivery queued.'));
    }
}
