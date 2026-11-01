<?php

namespace App\Jobs;

use App\Channels\Telegram;

class HandleTelegramUpdate extends TenantJob
{
    public int $tries = 2;

    public function __construct(int $workspaceId, public array $update)
    {
        parent::__construct($workspaceId);
        $this->onQueue('channels');
    }

    public function process(Telegram $telegram): void
    {
        $telegram->handle($this->update);
    }
}
