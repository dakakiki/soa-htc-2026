<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\Exam;
use App\Domain\Assessment\Models\ExamRound;
use App\Domain\Competition\Support\AttemptGrader;
use App\Domain\Competition\Support\ResultLedger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExamRoundController extends Controller
{
    /**
     * Rounds come back in the order they run in. Every select, filter and
     * results grid reads this list, so ordering it here orders all of them.
     */
    public function index(): JsonResponse
    {
        $this->authorize('content.manage');

        return response()->json([
            'data' => ExamRound::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'active', 'sort_order', 'is_current', 'is_sample']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('content.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:exam_rounds,name'],
            'active' => ['sometimes', 'boolean'],
        ]);
        // A new round lands at the end; where it belongs is a move away.
        $data['sort_order'] = (int) ExamRound::query()->max('sort_order') + 1;

        return response()->json(['data' => ExamRound::create($data)->only(['id', 'name', 'active', 'sort_order', 'is_current', 'is_sample'])], 201);
    }

    public function update(Request $request, ExamRound $examRound): JsonResponse
    {
        $this->authorize('content.manage');
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('exam_rounds', 'name')->ignore($examRound)],
            'active' => ['sometimes', 'boolean'],
            'is_current' => ['sometimes', 'boolean'],
        ]);

        // At most one round is being run at a time, so marking one puts the
        // others down in the same breath. Unmarking leaves none current, which
        // is a real answer: between rounds, none of them is.
        DB::transaction(function () use ($examRound, $data): void {
            if ($data['is_current'] ?? false) {
                ExamRound::query()->whereKeyNot($examRound->id)->where('is_current', true)->update(['is_current' => false]);
            }
            $examRound->update($data);
        });

        return response()->json(['data' => $examRound->only(['id', 'name', 'active', 'sort_order', 'is_current', 'is_sample'])]);
    }

    /**
     * The whole order in one call. The client sends the ids as it wants to see
     * them and the positions are written from that — moving one round never
     * leaves the list half-renumbered, and two admins cannot interleave their
     * moves into an order neither of them chose.
     */
    public function reorder(Request $request): JsonResponse
    {
        $this->authorize('content.manage');
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:exam_rounds,id'],
        ]);

        DB::transaction(function () use ($data): void {
            foreach ($data['ids'] as $position => $id) {
                ExamRound::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });

        return $this->index();
    }

    /**
     * A round that exams sit in cannot be deleted, and the practice round never
     * can. Neither refusal is caution — both close a hole that failed silently.
     *
     * `exams.exam_round_id` is `nullOnDelete`, so deleting a round in use does
     * not fail: it quietly unhooks every exam that sat in it. Publishing then
     * reports success and writes nothing, because the results layer is joined
     * through the round.
     *
     * And the practice round carries `is_sample`, which the whole results domain
     * turns on ({@see AttemptGrader},
     * {@see ResultLedger}). Lose it and practice
     * stops publishing itself and starts counting as an official result.
     *
     * Renaming, by contrast, is now harmless — which is the point of the flag.
     */
    public function destroy(ExamRound $examRound): Response|JsonResponse
    {
        $this->authorize('content.manage');

        if ($examRound->is_sample) {
            return response()->json(['message' => __('messages.content.round_is_practice')], 422);
        }

        if (Exam::query()->where('exam_round_id', $examRound->id)->exists()) {
            return response()->json(['message' => __('messages.content.round_in_use')], 422);
        }

        $examRound->delete();

        return response()->noContent();
    }
}
