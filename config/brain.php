<?php

use App\Brain\Tools\BusinessReportTool;
use App\Brain\Tools\CompleteTaskTool;
use App\Brain\Tools\CreateLeadTool;
use App\Brain\Tools\CreateTaskTool;
use App\Brain\Tools\CurrentTimeTool;
use App\Brain\Tools\FindLeadsTool;
use App\Brain\Tools\ListTasksTool;
use App\Brain\Tools\QueryDatasetTool;
use App\Brain\Tools\SearchKnowledgeTool;
use App\Brain\Tools\SendEmailTool;
use App\Brain\Tools\TriggerWebhookTool;
use App\Brain\Tools\UpdateLeadTool;

/*
|--------------------------------------------------------------------------
| AI Business Brain
|--------------------------------------------------------------------------
| One brain, any LLM. Every provider below is selected by name at runtime
| (per request, per conversation, or globally via BRAIN_PROVIDER).
|
| Drivers:
|   openai     - any OpenAI-compatible API (OpenAI, Groq, DeepSeek, Mistral,
|                OpenRouter, xAI, Together, Azure, Ollama, LM Studio, vLLM...)
|   anthropic  - Anthropic Claude (Messages API)
|   gemini     - Google Gemini (Generative Language API)
|   fake       - offline echo driver for tests and demos
*/

return [

    // Requests per minute per API token / user / IP on the brain API.
    'rate_limit' => (int) env('BRAIN_RATE_LIMIT', 60),

    'default' => env('BRAIN_PROVIDER', 'openai'),

    // Which provider creates embeddings for the knowledge base.
    // Set to null to use keyword search only (works with any LLM, zero cost).
    'embeddings' => [
        'provider' => env('BRAIN_EMBEDDING_PROVIDER', 'openai'),
        'dimensions' => (int) env('BRAIN_EMBEDDING_DIMENSIONS', 1536),
        'batch_size' => 64,
    ],

    'providers' => [

        'openai' => [
            'driver' => 'openai',
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-5-mini'),
            'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
            // OpenAI reasoning models want max_completion_tokens; most compatible servers want max_tokens.
            'max_tokens_param' => 'max_completion_tokens',
        ],

        'anthropic' => [
            'driver' => 'anthropic',
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5'),
            'version' => '2023-06-01',
        ],

        'gemini' => [
            'driver' => 'gemini',
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
        ],

        'groq' => [
            'driver' => 'openai',
            'base_url' => 'https://api.groq.com/openai/v1',
            'api_key' => env('GROQ_API_KEY'),
            'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'max_tokens_param' => 'max_tokens',
        ],

        'deepseek' => [
            'driver' => 'openai',
            'base_url' => 'https://api.deepseek.com/v1',
            'api_key' => env('DEEPSEEK_API_KEY'),
            'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'max_tokens_param' => 'max_tokens',
        ],

        'mistral' => [
            'driver' => 'openai',
            'base_url' => 'https://api.mistral.ai/v1',
            'api_key' => env('MISTRAL_API_KEY'),
            'model' => env('MISTRAL_MODEL', 'mistral-large-latest'),
            'embedding_model' => 'mistral-embed',
            'max_tokens_param' => 'max_tokens',
        ],

        'xai' => [
            'driver' => 'openai',
            'base_url' => 'https://api.x.ai/v1',
            'api_key' => env('XAI_API_KEY'),
            'model' => env('XAI_MODEL', 'grok-4'),
            'max_tokens_param' => 'max_tokens',
        ],

        'openrouter' => [
            'driver' => 'openai',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => env('OPENROUTER_API_KEY'),
            'model' => env('OPENROUTER_MODEL', 'openai/gpt-5-mini'),
            'max_tokens_param' => 'max_tokens',
            'headers' => ['HTTP-Referer' => env('APP_URL', 'http://localhost'), 'X-Title' => env('APP_NAME', 'AI Business Brain')],
        ],

        'together' => [
            'driver' => 'openai',
            'base_url' => 'https://api.together.xyz/v1',
            'api_key' => env('TOGETHER_API_KEY'),
            'model' => env('TOGETHER_MODEL', 'meta-llama/Llama-3.3-70B-Instruct-Turbo'),
            'max_tokens_param' => 'max_tokens',
        ],

        // Azure OpenAI: base_url = https://{resource}.openai.azure.com/openai/deployments/{deployment}
        'azure' => [
            'driver' => 'openai',
            'base_url' => env('AZURE_OPENAI_BASE_URL'),
            'api_key' => env('AZURE_OPENAI_API_KEY'),
            'auth_header' => 'api-key',
            'query' => ['api-version' => env('AZURE_OPENAI_API_VERSION', '2024-10-21')],
            'model' => env('AZURE_OPENAI_MODEL', 'gpt-4o-mini'),
            'max_tokens_param' => 'max_tokens',
        ],

        // Local, free, private. `ollama pull llama3.1` and `ollama pull nomic-embed-text`.
        'ollama' => [
            'driver' => 'openai',
            'enabled' => env('OLLAMA_ENABLED', false),
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434/v1'),
            'api_key' => 'ollama',
            'model' => env('OLLAMA_MODEL', 'llama3.1'),
            'embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
            'max_tokens_param' => 'max_tokens',
        ],

        'lmstudio' => [
            'driver' => 'openai',
            'enabled' => env('LMSTUDIO_ENABLED', false),
            'base_url' => env('LMSTUDIO_BASE_URL', 'http://localhost:1234/v1'),
            'api_key' => 'lm-studio',
            'model' => env('LMSTUDIO_MODEL', 'local-model'),
            'max_tokens_param' => 'max_tokens',
        ],

        'fake' => [
            'driver' => 'fake',
        ],
    ],

    // Generation defaults (any provider). Leave temperature null for reasoning models.
    'generation' => [
        'temperature' => env('BRAIN_TEMPERATURE') !== null ? (float) env('BRAIN_TEMPERATURE') : null,
        'max_tokens' => (int) env('BRAIN_MAX_TOKENS', 2048),
        'timeout' => (int) env('BRAIN_TIMEOUT', 120),
        'retries' => 2,
    ],

    // Who the brain works for. Override with a markdown file for long instructions.
    'business' => [
        'name' => env('BRAIN_BUSINESS_NAME', env('APP_NAME', 'Our Business')),
        'description' => env('BRAIN_BUSINESS_DESCRIPTION', ''),
        'tone' => env('BRAIN_TONE', 'professional, warm and concise'),
        'instructions_path' => storage_path('app/brain/instructions.md'),
    ],

    'knowledge' => [
        'auto_context' => true,          // inject top matches into every answer
        'top_k' => 5,
        'min_score' => 0.25,             // cosine floor for vector results
        'chunk_size' => 1200,            // characters
        'chunk_overlap' => 200,
        'disk' => env('BRAIN_DISK', 'local'),
        'max_upload_kb' => 20480,
        'queue' => env('BRAIN_INGEST_QUEUE', true), // ingest in a queued job
    ],

    // database = embeddings stored as JSON, cosine in PHP (MySQL, MariaDB, SQLite, Postgres)
    // pgvector = native Postgres vector index (requires the pgvector extension)
    'vector_store' => env('BRAIN_VECTOR_STORE', 'database'),

    'memory' => [
        'history_messages' => 30,
    ],

    'agent' => [
        'max_steps' => 8,
    ],

    'tools' => [
        // Actions that change the world (email, webhooks) wait for a human to approve.
        'require_approval' => env('BRAIN_REQUIRE_APPROVAL', true),
        'webhook_url' => env('BRAIN_WEBHOOK_URL'), // n8n, Zapier, Make, your ERP...
        'enabled' => [
            SearchKnowledgeTool::class,
            CurrentTimeTool::class,
            CreateLeadTool::class,
            FindLeadsTool::class,
            UpdateLeadTool::class,
            CreateTaskTool::class,
            ListTasksTool::class,
            CompleteTaskTool::class,
            BusinessReportTool::class,
            QueryDatasetTool::class,
            SendEmailTool::class,
            TriggerWebhookTool::class,
        ],
    ],

    /*
    | Security & compliance (see docs/COMPLIANCE.md)
    */
    'security' => [
        'encrypt_at_rest' => env('BRAIN_ENCRYPT_AT_REST', true),     // messages + lead notes sealed with APP_KEY
        'allow_private_urls' => env('BRAIN_ALLOW_PRIVATE_URLS', false), // SSRF guard for fetches/webhooks
        'force_https' => env('BRAIN_FORCE_HTTPS', false),
        'password_min' => (int) env('BRAIN_PASSWORD_MIN', 12),
        'password_uncompromised' => env('BRAIN_PASSWORD_UNCOMPROMISED', true), // haveibeenpwned k-anonymity check
        'lockout_attempts' => (int) env('BRAIN_LOCKOUT_ATTEMPTS', 8),
        'lockout_minutes' => (int) env('BRAIN_LOCKOUT_MINUTES', 15),
        'auth_event_retention_days' => (int) env('BRAIN_AUTH_LOG_DAYS', 400),
        // Providers you have a signed BAA with. Only these are usable in HIPAA mode.
        'hipaa_providers' => array_filter(explode(',', env('BRAIN_HIPAA_PROVIDERS', 'ollama,lmstudio'))),
    ],
];
