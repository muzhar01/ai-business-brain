<?php

namespace App\Models;

use App\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    /** Append-only, hash-chained per workspace: any edit or deletion breaks the chain (brain:audit:verify). */
    public static function record(string $action, ?Model $subject = null, array $meta = []): self
    {
        return DB::transaction(function () use ($action, $subject, $meta) {
            $workspaceId = Tenant::id();
            $prev = self::where('workspace_id', $workspaceId)->orderByDesc('id')->lockForUpdate()->value('hash');

            $log = new self([
                'workspace_id' => $workspaceId,
                'user_id' => auth()->id(),
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'meta' => $meta ?: null,
                'ip' => request()?->ip(),
            ]);
            $log->created_at = now()->startOfSecond();
            $log->prev_hash = $prev;
            $log->hash = $log->computeHash();
            $log->save();

            return $log;
        });
    }

    public function computeHash(): string
    {
        return hash('sha256', implode('|', [
            $this->prev_hash, $this->workspace_id, $this->user_id, $this->action, $this->subject_type, $this->subject_id,
            json_encode($this->meta), $this->ip, $this->created_at?->format('Y-m-d H:i:s'),
        ]));
    }

    /** @return int|null id of the first broken record, null if the chain is intact */
    public static function verifyChain(?int $workspaceId): ?int
    {
        $prev = null;
        foreach (self::where('workspace_id', $workspaceId)->orderBy('id')->lazyById(500) as $log) {
            if ($log->prev_hash !== $prev || $log->hash !== $log->computeHash()) {
                return $log->id;
            }
            $prev = $log->hash;
        }

        return null;
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit logs are append-only.'));
        static::deleting(fn () => throw new \LogicException('Audit logs are append-only.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
