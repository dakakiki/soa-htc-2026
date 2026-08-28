<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Support;

use App\Domain\Assessment\Models\Question;
use DateTimeInterface;
use Illuminate\Support\Facades\URL;

/**
 * Where a question's picture and its recording live, and how each caller is let
 * at them.
 *
 * Until 2026-08-28 both went to the `public` disk and were quoted to the client
 * as `{APP_URL}/storage/questions/…`. The question LIST was gated by difficulty
 * level, but the bytes were not gated at all: an exam's pictures and its
 * listening audio came down over plain HTTP, to anybody, with no session of any
 * kind. They now live on the private disk and leave it only through a route.
 *
 * Two doors, because the two callers cannot carry the same key:
 *
 *  - **Staff** arrive with the SPA's session cookie, which the browser attaches
 *    to `<img src>` and `<a href>` by itself. Their address is an ordinary
 *    authorised endpoint.
 *  - **A competitor** authenticates with a BEARER token, and no `<img>` or
 *    `<audio>` tag can send one. Their address is therefore signed at the moment
 *    the exam payload is built — behind `student.session`, so a live session is
 *    still what earns it — and it dies with the attempt it was minted for.
 *
 * The signature is the competitor's credential, which is worth saying plainly:
 * inside its window the address works for whoever holds it. That is a much
 * smaller thing than the open directory it replaces, and it is bounded by one
 * question and one attempt's clock.
 */
final class QuestionMedia
{
    /** The disk the bytes live on — `storage/app/private`, served by nothing. */
    public const DISK = 'local';

    /**
     * Directory within the disk. Deliberately the same name it had on the public
     * disk, so the paths already in `questions.image_path` stayed correct and
     * only the files had to move.
     */
    public const DIRECTORY = 'questions';

    /** @var array<string, string> the kind in the URL => the column that holds its path */
    public const KINDS = ['image' => 'image_path', 'audio' => 'audio_path'];

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys(self::KINDS);
    }

    /** The stored path, or null when the question has no file of that kind. */
    public static function path(Question $question, string $kind): ?string
    {
        $column = self::KINDS[$kind] ?? null;

        if ($column === null) {
            return null;
        }

        $path = $question->{$column};

        return is_string($path) && $path !== '' ? $path : null;
    }

    /** The staff address: an authorised endpoint, opened by the session cookie. */
    public static function staffUrl(Question $question, string $kind): ?string
    {
        return self::path($question, $kind) === null
            ? null
            : URL::route('questions.media', ['question' => $question->getKey(), 'kind' => $kind]);
    }

    /** The competitor's address: signed, and refused once `$until` has passed. */
    public static function signedUrl(Question $question, string $kind, DateTimeInterface $until): ?string
    {
        return self::path($question, $kind) === null
            ? null
            : URL::temporarySignedRoute('student.questions.media', $until, [
                'question' => $question->getKey(),
                'kind' => $kind,
            ]);
    }
}
