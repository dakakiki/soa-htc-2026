<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\Question;
use App\Domain\Assessment\Support\QuestionMedia;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only way out of the private disk for a question's picture and recording.
 *
 * Two actions rather than one route with two guards: the callers authenticate
 * by different means and neither can be talked into the other's. See
 * {@see QuestionMedia} for why.
 */
class QuestionMediaController extends Controller
{
    /** Staff — the same permission that may read the question at all. */
    public function show(Question $question, string $kind): BinaryFileResponse
    {
        $this->authorize('content.manage');

        return $this->stream($question, $kind);
    }

    /**
     * A competitor sitting the test.
     *
     * There is no session checked here, and that is the design rather than an
     * oversight: `<img src>` sends no bearer token, so the signature is the
     * credential. It was minted behind `student.session` when the exam payload
     * was built, names one question, and expires with the attempt.
     */
    public function student(Question $question, string $kind): BinaryFileResponse
    {
        return $this->stream($question, $kind);
    }

    /**
     * `response()->file()` rather than `Storage::download()` for two reasons the
     * exam screen depends on: the browser must SHOW the picture rather than save
     * it, and Symfony's file response answers a `Range` request — which is what
     * lets a competitor scrub back through a listening exercise instead of
     * waiting out the whole recording again.
     *
     * `nosniff` because the content type is guessed from the file. Uploads are
     * already held to raster images and audio by `StoreQuestionRequest`, so this
     * is the second lock rather than the first.
     *
     * 🪤 `setPrivate()` is not decoration. `BinaryFileResponse` constructs itself
     * with `$public = true` and calls `setPublic()` AFTER the headers it was
     * given, so a `Cache-Control` passed in the array below would lose and this
     * answers `Cache-Control: public` — on a resource whose whole point is that
     * it is not. Today's single Apache host has no shared cache to store it, but
     * `docs/02` puts a load balancer and a CDN in the target picture, and a
     * private exam recording is not something to leave depending on that.
     * `private` rather than `no-store`: the competitor's own browser SHOULD keep
     * the recording, or scrubbing back through it fetches it again.
     */
    private function stream(Question $question, string $kind): BinaryFileResponse
    {
        $path = QuestionMedia::path($question, $kind);

        abort_if($path === null, Response::HTTP_NOT_FOUND);
        abort_unless(Storage::disk(QuestionMedia::DISK)->exists($path), Response::HTTP_NOT_FOUND);

        return response()->file(
            Storage::disk(QuestionMedia::DISK)->path($path),
            ['X-Content-Type-Options' => 'nosniff'],
        )->setPrivate();
    }
}
