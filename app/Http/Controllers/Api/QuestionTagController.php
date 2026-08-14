<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\QuestionTag;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class QuestionTagController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('content.manage');

        return response()->json(['data' => QuestionTag::query()->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('content.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:255', 'unique:question_tags,name']]);

        return response()->json(['data' => QuestionTag::create($data)->only(['id', 'name'])], 201);
    }

    public function update(Request $request, QuestionTag $questionTag): JsonResponse
    {
        $this->authorize('content.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('question_tags', 'name')->ignore($questionTag)],
        ]);
        $questionTag->update($data);

        return response()->json(['data' => $questionTag->only(['id', 'name'])]);
    }

    public function destroy(QuestionTag $questionTag): Response
    {
        $this->authorize('content.manage');
        $questionTag->delete();

        return response()->noContent();
    }
}
