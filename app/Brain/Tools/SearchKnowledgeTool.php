<?php

namespace App\Brain\Tools;

use App\Brain\Contracts\Tool;
use App\Brain\Knowledge\KnowledgeBase;
use App\Models\Conversation;

class SearchKnowledgeTool implements Tool
{
    public function __construct(protected KnowledgeBase $knowledge) {}

    public function name(): string
    {
        return 'search_knowledge';
    }

    public function description(): string
    {
        return 'Search the company knowledge base (policies, prices, products, SOPs, contracts, FAQs). Use it before answering any business question you are not sure about.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'What to look for, in plain words.'],
                'collection' => ['type' => 'string', 'description' => 'Optional collection name, e.g. hr, sales, support.'],
            ],
            'required' => ['query'],
        ];
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function handle(array $arguments, ?Conversation $conversation = null): string|array
    {
        $results = $this->knowledge->search($arguments['query'], 6, $arguments['collection'] ?? null);

        if ($results->isEmpty()) {
            return 'No matching documents found.';
        }

        return $results->map(fn ($r) => "[{$r['title']}] {$r['content']}")->implode("\n\n---\n\n");
    }
}
