<?php

namespace App\Etl;

use App\Models\Lead;

/** LOAD destinations and their fields (for the mapping UI, validation and auto-mapping). */
class Targets
{
    public static function all(): array
    {
        return [
            'leads' => [
                'label' => 'CRM leads / customers',
                'fields' => [
                    'name' => ['required' => true, 'synonyms' => ['full name', 'contact', 'customer', 'client', 'contact name', 'customer name', 'naam']],
                    'email' => ['transform' => 'email', 'synonyms' => ['e-mail', 'mail', 'email address']],
                    'phone' => ['transform' => 'phone', 'synonyms' => ['mobile', 'cell', 'telephone', 'tel', 'whatsapp', 'contact number', 'phone number']],
                    'company' => ['synonyms' => ['organization', 'organisation', 'business', 'firm', 'account', 'company name']],
                    'status' => ['transform' => 'lower', 'enum' => Lead::STATUSES, 'synonyms' => ['stage', 'pipeline', 'lead status']],
                    'value' => ['transform' => 'number', 'synonyms' => ['amount', 'deal value', 'revenue', 'deal size', 'price', 'budget']],
                    'source' => ['synonyms' => ['lead source', 'channel', 'origin', 'utm source', 'campaign']],
                    'notes' => ['synonyms' => ['comments', 'description', 'remarks', 'note', 'details']],
                ],
                'match_on' => ['email', 'phone', 'name'],
            ],
            'tasks' => [
                'label' => 'Tasks',
                'fields' => [
                    'title' => ['required' => true, 'synonyms' => ['task', 'subject', 'name', 'todo', 'summary']],
                    'description' => ['synonyms' => ['details', 'notes', 'body']],
                    'due_date' => ['transform' => 'date', 'synonyms' => ['due', 'deadline', 'due on', 'date']],
                    'priority' => ['transform' => 'lower', 'enum' => ['low', 'normal', 'high', 'urgent'], 'synonyms' => ['importance', 'severity']],
                    'status' => ['transform' => 'lower', 'enum' => ['open', 'done'], 'synonyms' => ['state', 'completed']],
                    'assignee' => ['synonyms' => ['owner', 'assigned to', 'responsible', 'user']],
                ],
                'match_on' => ['title'],
            ],
            'dataset' => [
                'label' => 'Custom dataset (products, inventory, orders...)',
                'fields' => [], // free-form: any target name becomes a dataset field
                'match_on' => [],
            ],
            'knowledge' => [
                'label' => 'Knowledge base (each row becomes a document)',
                'fields' => [
                    'title' => ['required' => true, 'synonyms' => ['question', 'name', 'subject', 'heading', 'title']],
                    'content' => ['required' => true, 'synonyms' => ['answer', 'body', 'text', 'description', 'content', 'article']],
                    'collection' => ['synonyms' => ['category', 'section', 'topic', 'collection']],
                ],
                'match_on' => [],
            ],
        ];
    }

    public static function fields(string $target): array
    {
        return self::all()[$target]['fields'] ?? [];
    }
}
