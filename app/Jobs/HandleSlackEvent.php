<?php

namespace App\Jobs;

use App\Channels\Slack;

class HandleSlackEvent extends TenantJob
{
    public int $tries = 2;

    public function __construct(int $workspaceId, public array $event)
    {
        parent::__construct($workspaceId);
        $this->onQueue('channels');
    }

    public function process(Slack $slack): void
    {
        $slack->handle($this->event);
    }
}
