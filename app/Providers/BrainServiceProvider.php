<?php

namespace App\Providers;

use App\Brain\Brain;
use App\Brain\Contracts\VectorStore;
use App\Brain\Knowledge\KnowledgeBase;
use App\Brain\LlmManager;
use App\Brain\Tools\ToolRegistry;
use App\Brain\VectorStores\DatabaseVectorStore;
use App\Brain\VectorStores\PgVectorStore;
use App\Models\Conversation;
use App\Models\Export;
use App\Models\Import;
use App\Models\Lead;
use App\Models\PendingAction;
use App\Models\Task;
use App\Models\Workspace;
use App\Tenancy\Tenant;
use App\Webhooks\Events;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TickReceived;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;

class BrainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmManager::class);

        $this->app->singleton(VectorStore::class, function () {
            return config('brain.vector_store') === 'pgvector' && DB::getDriverName() === 'pgsql'
                ? new PgVectorStore
                : new DatabaseVectorStore;
        });

        $this->app->singleton(KnowledgeBase::class);

        $this->app->singleton(ToolRegistry::class, function ($app) {
            $registry = new ToolRegistry;
            foreach (config('brain.tools.enabled', []) as $class) {
                $registry->register($app->make($class));
            }

            return $registry;
        });

        $this->app->singleton(Brain::class);
    }

    public function boot(): void
    {
        $this->registerWebhookEvents();
        Event::listen(SocialiteWasCalled::class, [MicrosoftExtendSocialite::class, 'handle']);
        Gate::define('viewPulse', fn ($user) => (bool) $user->is_admin);
        $this->registerOctaneHooks();
        RateLimiter::for('brain', fn ($request) => Limit::perMinute((int) config('brain.rate_limit', 60))->by($request->bearerToken() ?: ($request->user()?->id ?: $request->ip())));
        RateLimiter::for('widget', fn ($request) => [
            Limit::perMinute(12)->by($request->ip()),
            Limit::perMinute(300)->by('widget:'.$request->route('key')),
        ]);
        Cashier::useCustomerModel(Workspace::class);

    }

    protected function registerWebhookEvents(): void
    {
        $lead = fn ($l) => $l->only(['id', 'name', 'email', 'phone', 'company', 'status', 'value', 'source', 'created_at', 'updated_at']);
        Lead::created(fn ($l) => Events::dispatch('lead.created', $lead($l)));
        Lead::updated(fn ($l) => Events::dispatch('lead.updated', $lead($l) + ['changed' => array_keys($l->getChanges())]));
        Task::created(fn ($t) => Events::dispatch('task.created', $t->toArray()));
        Task::updated(fn ($t) => $t->wasChanged('status') && $t->status === 'done' ? Events::dispatch('task.completed', $t->toArray()) : null);
        Conversation::created(fn ($c) => Events::dispatch('conversation.created', $c->only(['uuid', 'title', 'channel', 'created_at'])));
        PendingAction::created(fn ($a) => Events::dispatch('action.pending', $a->only(['id', 'tool', 'status', 'created_at'])));
        PendingAction::updated(fn ($a) => $a->wasChanged('status') && in_array($a->status, ['approved', 'rejected'], true)
            ? Events::dispatch('action.'.$a->status, $a->only(['id', 'tool', 'status', 'decided_by', 'decided_at'])) : null);
        Import::updated(fn ($i) => $i->wasChanged('status') && in_array($i->status, ['done', 'failed'], true)
            ? Events::dispatch($i->status === 'done' ? 'import.completed' : 'import.failed', $i->only(['id', 'name', 'target', 'total_rows', 'created_rows', 'updated_rows', 'failed_rows'])) : null);
        Export::updated(fn ($e) => $e->wasChanged('status') && $e->status === 'done' ? Events::dispatch('export.completed', $e->only(['id', 'target', 'format', 'rows'])) : null);
    }

    /** Octane keeps the app in memory: never let tenant state leak between requests. */
    protected function registerOctaneHooks(): void
    {
        if (! class_exists(RequestReceived::class)) {
            return;
        }
        $reset = function () {
            Tenant::forget();
            $this->app->make(LlmManager::class)->flush();
        };
        foreach ([RequestReceived::class, TaskReceived::class, TickReceived::class, RequestTerminated::class] as $event) {
            Event::listen($event, $reset);
        }
    }
}
