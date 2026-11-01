<?php

namespace App\Brain\Tools;

use App\Brain\Contracts\Tool;
use App\Models\Conversation;
use App\Models\Lead;

class CreateLeadTool implements Tool
{
    public function name(): string
    {
        return 'create_lead';
    }

    public function description(): string
    {
        return 'Save a new sales lead / prospect / customer in the CRM.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'phone' => ['type' => 'string'],
                'company' => ['type' => 'string'],
                'value' => ['type' => 'number', 'description' => 'Estimated deal value'],
                'source' => ['type' => 'string', 'description' => 'Where the lead came from'],
                'notes' => ['type' => 'string'],
            ],
            'required' => ['name'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function handle(array $arguments, ?Conversation $conversation = null): string|array
    {
        if (! empty($arguments['email']) && ($existing = Lead::where('email', $arguments['email'])->first())) {
            return ['already_exists' => true, 'lead' => $existing->toArray()];
        }

        $lead = Lead::create(collect($arguments)->only(['name', 'email', 'phone', 'company', 'value', 'source', 'notes'])->all());

        return ['created' => true, 'lead' => $lead->toArray()];
    }
}
