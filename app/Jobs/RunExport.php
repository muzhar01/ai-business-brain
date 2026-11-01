<?php

namespace App\Jobs;

use App\Etl\Exporter;
use App\Models\Export;

class RunExport extends TenantJob
{
    public function __construct(int $workspaceId, public int $exportId)
    {
        parent::__construct($workspaceId);
    }

    public function process(Exporter $exporter): void
    {
        if ($export = Export::find($this->exportId)) {
            $exporter->run($export);
        }
    }
}
