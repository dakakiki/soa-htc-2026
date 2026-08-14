<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Assessment\Models\TestType;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class TestTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('content.manage');

        return response()->json(['data' => TestType::query()->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('content.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:255', 'unique:test_types,name']]);

        return response()->json(['data' => TestType::create($data)->only(['id', 'name'])], 201);
    }

    public function update(Request $request, TestType $testType): JsonResponse
    {
        $this->authorize('content.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('test_types', 'name')->ignore($testType)],
        ]);
        $testType->update($data);

        return response()->json(['data' => $testType->only(['id', 'name'])]);
    }

    public function destroy(TestType $testType): Response
    {
        $this->authorize('content.manage');
        $testType->delete();

        return response()->noContent();
    }
}
