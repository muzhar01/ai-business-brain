<?php

namespace App\Tenancy;

use App\Models\Workspace;
use Closure;

/**
 * The workspace (tenant) the current request, job or command acts for.
 * Every tenant model is scoped to it automatically (see BelongsToWorkspace).
 */
class Tenant
{
    protected static ?Workspace $current = null;

    public static function set(?Workspace $workspace): void
    {
        static::$current = $workspace;
    }

    public static function current(): ?Workspace
    {
        return static::$current;
    }

    public static function id(): ?int
    {
        return static::$current?->id;
    }

    public static function check(): bool
    {
        return static::$current !== null;
    }

    public static function forget(): void
    {
        static::$current = null;
    }

    /** Run a callback as another tenant, restoring the previous one after. */
    public static function run(Workspace $workspace, Closure $callback): mixed
    {
        $previous = static::$current;
        static::$current = $workspace;

        try {
            return $callback($workspace);
        } finally {
            static::$current = $previous;
        }
    }
}
