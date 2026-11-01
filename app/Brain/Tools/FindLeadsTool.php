<?php

namespace App\Brain\Tools;

use App\Brain\Contracts\Tool;
use App\Models\Conversation;
use App\Models\Lead;

class FindLeadsTool implements Tool
{
    public function name(): string
    {
        return 'find_leads';
    }

    public function description(): string
    {
        return 'Look up leads/customers in the CRM by name, email, company or status.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => ['type' => 'string', 'description' => 'Name, email or company fragment'],
                'status' => ['type' => 'string', 'enum' => Lead::STATUSES],
                'limit' => ['type' => 'integer'],
            ],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function handle(array $arguments, ?Conversation $conversation = null): string|array
    {
        $leads = Lead::query()
            ->when($arguments['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('company', 'like', "%{$s}%")))
            ->when($arguments['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest()->limit(min((int) ($arguments['limit'] ?? 20), 100))->get();

        return $leads->isEmpty() ? 'No leads found.' : $leads->toArray();
    }
}
