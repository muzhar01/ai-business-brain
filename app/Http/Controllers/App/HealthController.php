<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Load-balancer / uptime probe: 200 when DB and cache answer, 503 otherwise. No secrets exposed. */
class HealthController extends Controller
{
    public function __invoke()
    {
        $checks = [];
        foreach (['database' => fn () => DB::select('select 1'), 'cache' => fn () => Cache::put('health', 1, 10) && Cache::get('health') === 1] as $name => $probe) {
            $t = microtime(true);
            try {
                $probe();
                $checks[$name] = ['ok' => true, 'ms' => round((microtime(true) - $t) * 1000, 1)];
            } catch (Throwable) {
                $checks[$name] = ['ok' => false];
            }
        }
        $ok = collect($checks)->every(fn ($c) => $c['ok']);

        return response()->json(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks], $ok ? 200 : 503);
    }
}
