<?php

use App\Http\Controllers\Brain\DataApiController;
use Illuminate\Support\Facades\Route;

/*
| Public REST API. Auth: "Authorization: Bearer brn_..." (Settings > API).
| Every route is automatically scoped to the token's workspace.
*/
Route::prefix('brain')->middleware(['api.token', 'throttle:brain'])->group(function () {
    require __DIR__.'/brain_shared.php';

    // ETL over the API
    Route::post('imports', [DataApiController::class, 'import']);
    Route::get('imports/{import}', [DataApiController::class, 'showImport']);
    Route::post('exports', [DataApiController::class, 'export']);
    Route::get('exports/{export}', [DataApiController::class, 'showExport']);
    Route::get('exports/{export}/download', [DataApiController::class, 'download']);
    Route::get('datasets', [DataApiController::class, 'datasets']);
    Route::get('datasets/{dataset}/rows', [DataApiController::class, 'rows']);
    Route::post('datasets/{dataset}/rows', [DataApiController::class, 'pushRows']);
});
