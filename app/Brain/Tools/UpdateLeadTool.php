<?php

namespace App\Brain\Tools;

use App\Brain\Contracts\Tool;
use App\Models\Conversation;
use App\Models\Lead;

class UpdateLeadTool implements Tool
{
    public function name(): string
    {
        return 'update_lead';
    }

    public function description(): string
    {
        return 'Update a lead: move it through the pipeline, change its value, or append a note.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => Lead::STATUSES],
                'value' => ['type' => 'number'],
                'note' => ['type' => 'string', 'description' => 'Appended to the notes with a timestamp'],
            ],
            'required' => ['id'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function handle(array $arguments, ?Conversation $conversation = null): string|array
    {
        $lead = Lead::find($arguments['id']);
        if (! $lead) {
            return "Lead #{$arguments['id']} not found.";
        }

        if (isset($arguments['status'])) {
            $lead->status = $arguments['status'];
        }
        if (isset($arguments['value'])) {
            $lead->value = $arguments['value'];
        }
        if (! empty($arguments['note'])) {
            $lead->notes = trim(($lead->notes ? $lead->notes."\n" : '').'['.now()->toDateTimeString().'] '.$arguments['note']);
        }
        $lead->save();

        return ['updated' => true, 'lead' => $lead->toArray()];
    }
}
