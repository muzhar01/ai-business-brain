<?php

namespace App\Jobs;

use App\Channels\WhatsApp;

class HandleWhatsAppUpdate extends TenantJob
{
    public int $tries = 2;

    public function __construct(int $workspaceId, public array $payload)
    {
        parent::__construct($workspaceId);
        $this->onQueue('channels');
    }

    public function process(WhatsApp $whatsapp): void
    {
        $whatsapp->handle($this->payload);
    }
}
