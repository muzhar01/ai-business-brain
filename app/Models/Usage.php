<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

class Usage extends Model
{
    protected $table = 'brain_usage';

    protected $guarded = ['id'];

    public static function current(Workspace $workspace): self
    {
        $key = ['workspace_id' => $workspace->id, 'period' => now()->format('Y-m')];

        try {
            return self::firstOrCreate($key);
        } catch (UniqueConstraintViolationException) {
            return self::where($key)->firstOrFail(); // lost a creation race to a parallel request
        }
    }

    public static function record(Workspace $workspace, array $increments): void
    {
        $increments = array_filter($increments);
        if ($increments) {
            // one atomic UPDATE ... SET a = a + ?, b = b + ? (safe under concurrency)
            self::current($workspace)->incrementEach($increments);
        }
    }
}
