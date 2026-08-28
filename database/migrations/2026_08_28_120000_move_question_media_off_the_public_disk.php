<?php

use App\Domain\Assessment\Support\QuestionMedia;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

/**
 * Exam media moves off the public disk.
 *
 * No schema changes anything here: `questions.image_path` and `audio_path` hold
 * a path relative to a disk, and only the disk changed. What has to move is the
 * bytes — anything already uploaded is sitting in `storage/app/public/questions`,
 * readable by anyone who can guess a file name, which is the whole reason for
 * this round.
 *
 * A file move in a migration rather than a console command, deliberately:
 * `migrate` is already in the deployment recipe and a command is a thing to
 * forget. It is idempotent and it is reversible, so it is safe wherever it
 * lands — including a `migrate:fresh` on a database whose files moved long ago,
 * and the test suite, where both directories are normally empty and it does
 * nothing at all.
 *
 * On the development machine this was a no-op when it was written: 1699
 * questions, none of them carrying a picture or a recording. It exists for the
 * install that is not this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->move('public', QuestionMedia::DISK);
    }

    public function down(): void
    {
        $this->move(QuestionMedia::DISK, 'public');
    }

    /**
     * Copy across, confirm it arrived, and only then drop the original.
     *
     * 🪤 The confirmation is not belt and braces. Both disks are configured
     * `'throw' => false, 'report' => false` (`config/filesystems.php`), and in
     * that mode `writeStream()` swallows `UnableToWriteFile` and returns FALSE
     * without an exception and without a log line. A full volume, a read-only
     * mount, or a `php` user that cannot write where `www-data` can would
     * therefore have deleted the only copy of a listening exercise while
     * `migrate` printed DONE and exited 0. Halting is the recoverable outcome:
     * it leaves every file readable exactly where it was.
     *
     * A name already present at the destination is left alone — re-running must
     * not overwrite the copy that is now the live one — and `fileExists()`
     * rather than `exists()`, so a directory of that name cannot pass for a
     * file that was moved.
     */
    private function move(string $from, string $to): void
    {
        foreach (Storage::disk($from)->files(QuestionMedia::DIRECTORY) as $path) {
            if (Storage::disk($to)->fileExists($path)) {
                Storage::disk($from)->delete($path);

                continue;
            }

            $stream = Storage::disk($from)->readStream($path);

            if ($stream === null) {
                throw new RuntimeException(
                    "Could not read [{$path}] from the [{$from}] disk. Nothing was deleted.",
                );
            }

            if (Storage::disk($to)->writeStream($path, $stream) !== true
                || ! Storage::disk($to)->fileExists($path)) {
                throw new RuntimeException(
                    "Could not move [{$path}] from the [{$from}] disk to [{$to}]. Nothing was deleted.",
                );
            }

            Storage::disk($from)->delete($path);
        }
    }
};
