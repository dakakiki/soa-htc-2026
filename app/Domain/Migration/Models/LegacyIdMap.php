<?php

declare(strict_types=1);

namespace App\Domain\Migration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lineage record: one legacy source row → one new target id. Keyed by
 * (source, source_table, source_pk, target_type) so re-running an import never
 * duplicates, and every legacy id — including ones merged away by dedup —
 * resolves back to its target.
 */
class LegacyIdMap extends Model
{
    /** The single legacy export in play today. */
    public const SOURCE = 'soa2024';

    protected $fillable = ['source', 'source_table', 'source_pk', 'target_type', 'target_id'];

    protected function casts(): array
    {
        return [
            'source_pk' => 'integer',
            'target_id' => 'integer',
        ];
    }

    /** Record (idempotently) that a legacy row maps to a target id. */
    public static function map(string $table, int $pk, string $type, int $targetId): void
    {
        static::query()->updateOrCreate(
            ['source' => self::SOURCE, 'source_table' => $table, 'source_pk' => $pk, 'target_type' => $type],
            ['target_id' => $targetId],
        );
    }

    /** Resolve a legacy row to its target id, or null if unmapped. */
    public static function resolve(string $table, int $pk, string $type): ?int
    {
        return static::query()
            ->where('source', self::SOURCE)
            ->where('source_table', $table)
            ->where('source_pk', $pk)
            ->where('target_type', $type)
            ->value('target_id');
    }
}
