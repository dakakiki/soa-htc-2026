<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Migration\LegacyText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off repair of the CP850 double-encoding the legacy import copied verbatim
 * into our tables (see {@see LegacyText}). Walks the human-text columns, reverses
 * the mojibake in place, and touches only rows that actually change.
 *
 * Idempotent and safe to re-run: clean rows are left untouched, and the reversal
 * is applied only when it yields valid, no-longer-corrupted UTF-8. Use
 * `--dry-run` to preview counts and samples without writing.
 */
class FixLegacyEncoding extends Command
{
    protected $signature = 'legacy:fix-encoding {--dry-run : Report what would change without writing}';

    protected $description = 'Repair CP850 mojibake in imported legacy text (school/coordinator names, question content, …)';

    /** Table => human-text columns to repair. Missing tables/columns are skipped. */
    private const TARGETS = [
        'schools' => ['name', 'city', 'address'],
        'users' => ['name', 'city', 'address'],
        'questions' => ['title', 'description'],
        'question_answers' => ['text'],
        'question_tags' => ['name'],
        'countries' => ['name'],
        'regions' => ['name'],
        'difficulty_categories' => ['name'],
        'quizzes' => ['title', 'description'],
        'exams' => ['title', 'description'],
        'tests' => ['title', 'description'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $grandRows = 0;
        $grandFields = 0;

        foreach (self::TARGETS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $columns = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
            if ($columns === []) {
                continue;
            }

            $rowsChanged = 0;
            $fieldsChanged = 0;
            $samples = [];

            DB::table($table)->orderBy('id')->select(array_merge(['id'], $columns))
                ->chunkById(1000, function ($rows) use ($table, $columns, $dryRun, &$rowsChanged, &$fieldsChanged, &$samples): void {
                    foreach ($rows as $row) {
                        $update = [];
                        foreach ($columns as $col) {
                            $fixed = LegacyText::fix($row->$col);
                            if ($fixed !== $row->$col) {
                                $update[$col] = $fixed;
                                $fieldsChanged++;
                                if (count($samples) < 3) {
                                    $samples[] = '   '.$this->clip($row->$col).' → '.$this->clip($fixed);
                                }
                            }
                        }
                        if ($update !== []) {
                            $rowsChanged++;
                            if (! $dryRun) {
                                DB::table($table)->where('id', $row->id)->update($update);
                            }
                        }
                    }
                });

            if ($fieldsChanged > 0) {
                $this->line(sprintf('%-22s rows=%-5d fields=%-5d', $table, $rowsChanged, $fieldsChanged));
                foreach ($samples as $s) {
                    $this->line($s);
                }
            }
            $grandRows += $rowsChanged;
            $grandFields += $fieldsChanged;
        }

        $verb = $dryRun ? 'Would repair' : 'Repaired';
        $this->newLine();
        $this->info("{$verb} {$grandFields} field(s) across {$grandRows} row(s).".($dryRun ? ' (dry run — nothing written)' : ''));

        return self::SUCCESS;
    }

    /** Short single-line preview of a value for the report. */
    private function clip(?string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return '«'.mb_strimwidth($value, 0, 60, '…').'»';
    }
}
