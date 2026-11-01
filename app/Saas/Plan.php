<?php

namespace App\Saas;

class Plan
{
    public function __construct(public readonly string $key, public readonly array $config) {}

    public static function find(?string $key): self
    {
        $plans = config('saas.plans');
        $key = isset($plans[$key]) ? $key : config('saas.default_plan', 'free');

        return new self($key, $plans[$key]);
    }

    public static function all(): array
    {
        return collect(config('saas.plans'))->map(fn ($c, $k) => new self($k, $c))->all();
    }

    public static function forStripePrice(?string $price): ?self
    {
        foreach (self::all() as $plan) {
            if ($price && $plan->config['stripe_price'] === $price) {
                return $plan;
            }
        }

        return null;
    }

    public function name(): string
    {
        return $this->config['name'];
    }

    public function limit(string $key): int
    {
        return (int) ($this->config['limits'][$key] ?? 0);
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->config['features'] ?? [], true);
    }

    public function allowsPlatformKeys(): bool
    {
        return (bool) ($this->config['platform_keys'] ?? false);
    }
}
