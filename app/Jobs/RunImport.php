<?php

namespace App\Jobs;

use App\Etl\Importer;
use App\Models\Import;

class RunImport extends TenantJob
{
    public int $tries = 1;

    public function __construct(int $workspaceId, public int $importId)
    {
        parent::__construct($workspaceId);
    }

    public function process(Importer $importer): void
    {
        if ($import = Import::find($this->importId)) {
            $importer->run($import);
        }
    }
}
