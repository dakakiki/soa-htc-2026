<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\ExamRound;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ExamRoundController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('content.manage');

        return response()->json(['data' => ExamRound::query()->orderBy('name')->get(['id', 'name', 'active'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('content.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:exam_rounds,name'],
            'active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => ExamRound::create($data)->only(['id', 'name', 'active'])], 201);
    }

    public function update(Request $request, ExamRound $examRound): JsonResponse
    {
        $this->authorize('content.manage');
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('exam_rounds', 'name')->ignore($examRound)],
            'active' => ['sometimes', 'boolean'],
        ]);
        $examRound->update($data);

        return response()->json(['data' => $examRound->only(['id', 'name', 'active'])]);
    }

    public function destroy(ExamRound $examRound): Response
    {
        $this->authorize('content.manage');
        $examRound->delete();

        return response()->noContent();
    }
}
