<?php

namespace App\Brain;

use App\Brain\Contracts\LlmDriver;
use App\Brain\Drivers\AnthropicDriver;
use App\Brain\Drivers\FakeDriver;
use App\Brain\Drivers\GeminiDriver;
use App\Brain\Drivers\OpenAiCompatibleDriver;
use App\Brain\Exceptions\BrainException;
use App\Tenancy\Tenant;
use Closure;

/**
 * Resolves any provider by name, per tenant:
 *   1. the workspace's own key (BYOK, Settings > AI providers), else
 *   2. the platform key from .env, if the workspace's plan allows it.
 * Workspaces can also add custom OpenAI-compatible endpoints under any name.
 */
class LlmManager
{
    /** @var array<string, LlmDriver> */
    protected array $resolved = [];

    /** @var array<string, Closure> */
    protected array $customCreators = [];

    public function provider(?string $name = null): LlmDriver
    {
        $name ??= $this->defaultProvider();

        return $this->resolved[(Tenant::id() ?? 0).':'.$name] ??= $this->make($name);
    }

    public function embedder(): ?LlmDriver
    {
        $name = Tenant::current()?->setting('embedding_provider') ?: config('brain.embeddings.provider');
        if (! $name || $name === 'null' || ! $this->isConfigured($name)) {
            return null;
        }
        $driver = $this->provider($name);

        return $driver->supportsEmbeddings() ? $driver : null;
    }

    public function defaultProvider(): string
    {
        $preferred = Tenant::current()?->setting('default_provider');
        if ($preferred && $this->isConfigured($preferred)) {
            return $preferred;
        }
        $default = (string) config('brain.default');
        if ($this->isConfigured($default)) {
            return $default;
        }

        return collect($this->names())->first(fn ($n) => $this->isConfigured($n) && $this->config($n)['driver'] !== 'fake') ?? $default;
    }

    public function extend(string $driver, Closure $creator): static
    {
        $this->customCreators[$driver] = $creator;

        return $this;
    }

    public function flush(): void
    {
        $this->resolved = [];
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(config('brain.providers', [])),
            array_keys(Tenant::current()?->provider_keys ?? []),
        )));
    }

    /** Effective config for a provider in the current tenant. */
    public function config(string $name): ?array
    {
        $base = config("brain.providers.{$name}");
        $workspace = Tenant::current();
        $own = $workspace?->providerKey($name);

        // HIPAA mode: only providers covered by a BAA (configured, or flagged by the workspace).
        if ($workspace?->hipaa() && ! in_array($name, config('brain.security.hipaa_providers', []), true) && empty($own['baa'])) {
            return null;
        }

        if (! $base && ! $own) {
            return null;
        }

        $config = $base ?? ['driver' => 'openai', 'max_tokens_param' => 'max_tokens'];
        $config['source'] = 'platform';

        if ($own && filled($own['api_key'] ?? null)) {
            $config = array_merge($config, array_filter($own, fn ($v) => filled($v)));
            $config['source'] = 'workspace';
            $config['guard_url'] = filled($own['base_url'] ?? null); // tenant-chosen endpoint: SSRF-guard it
        } elseif ($workspace && ! $workspace->plan()->allowsPlatformKeys() && ! $this->isLocal($config)) {
            $config['api_key'] = null; // plan requires bring-your-own-key
        }

        return $config;
    }

    public function available(): array
    {
        return collect($this->names())
            ->filter(fn ($name) => $this->isConfigured($name))
            ->reject(fn ($name) => $this->config($name)['driver'] === 'fake' && config('brain.default') !== 'fake')
            ->map(function ($name) {
                $c = $this->config($name);

                return [
                    'name' => $name,
                    'driver' => $c['driver'],
                    'model' => $c['model'] ?? null,
                    'embeddings' => ! empty($c['embedding_model']),
                    'source' => $c['source'],
                    'default' => $name === $this->defaultProvider(),
                ];
            })->values()->all();
    }

    public function isConfigured(string $name): bool
    {
        $c = $this->config($name);
        if (! $c) {
            return false;
        }
        if ($c['driver'] === 'fake') {
            return app()->environment(['local', 'testing']);
        }
        if (array_key_exists('enabled', $c) && $c['source'] === 'platform') {
            return (bool) $c['enabled'] && filled($c['base_url'] ?? null);
        }

        return filled($c['api_key'] ?? null) && filled($c['base_url'] ?? null);
    }

    protected function isLocal(array $config): bool
    {
        return array_key_exists('enabled', $config) || ($config['driver'] ?? null) === 'fake';
    }

    protected function make(string $name): LlmDriver
    {
        $config = $this->config($name);
        if (! $config) {
            throw new BrainException("Unknown LLM provider [{$name}].");
        }
        if ($config['driver'] !== 'fake' && ! $this->isConfigured($name)) {
            throw BrainException::provider($name, 'No API key. Add one in Settings > AI providers.');
        }

        if (isset($this->customCreators[$config['driver']])) {
            return ($this->customCreators[$config['driver']])($name, $config);
        }

        return match ($config['driver']) {
            'openai' => new OpenAiCompatibleDriver($name, $config),
            'anthropic' => new AnthropicDriver($name, $config),
            'gemini' => new GeminiDriver($name, $config),
            'fake' => new FakeDriver($name, $config),
            default => throw new BrainException("Unsupported driver [{$config['driver']}] for provider [{$name}]."),
        };
    }
}
