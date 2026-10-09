<?php

namespace MlSolutions\NovaLogsView\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use MlSolutions\NovaLogsView\Support\LogExplorer;

final class LogVisibilityController extends Controller
{
    public function __construct(private readonly LogExplorer $explorer) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'file' => ['nullable', 'string', 'max:64'],
            'level' => ['nullable', 'string', 'max:32'],
            'channel' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:120'],
            'severity' => ['nullable', Rule::in(['failures'])],
            'has_trace' => ['nullable', Rule::in(['1'])],
            'period' => ['nullable', Rule::in(['1h', '6h', '24h', '7d', '30d'])],
            'fingerprint' => ['nullable', 'string', 'size:24', 'regex:/^[a-f0-9]+$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
        ]);

        return response()->json($this->explorer->search($filters));
    }

    public function show(Request $request, string $entry): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'string', 'max:64'],
        ]);

        abort_unless(preg_match('/^[a-f0-9]{64}$/', $entry) === 1, 404);

        $detail = $this->explorer->detail($entry, $validated['file']);

        abort_if($detail === null, 404);

        unset($detail['timestamp_unix']);

        return response()->json(['data' => $detail]);
    }
}
