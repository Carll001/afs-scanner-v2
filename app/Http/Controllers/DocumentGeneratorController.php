<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentBatchStoreRequest;
use App\Jobs\GenerateDocumentBatchItemJob;
use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Services\ExcelExtractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentGeneratorController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('DocumentGenerator', [
            'initialHistory' => $this->historyPayload($request, null),
        ]);
    }

    public function store(
        DocumentBatchStoreRequest $request,
        ExcelExtractionService $excelExtractionService
    ): JsonResponse {
        $sheetIndex = (int) $request->integer('sheet_index', 0);

        $excelFile = $request->file('excel_file');
        $templateFile = $request->file('template_file');
        if (! $excelFile || ! $templateFile) {
            return response()->json(['message' => 'Files are required.'], 422);
        }

        $excelPath = $excelFile->store("document-generator/{$request->user()->id}/uploads", 'local');
        $templatePath = $templateFile->store("document-generator/{$request->user()->id}/uploads", 'local');

        $extracted = $excelExtractionService->extract(Storage::disk('local')->path($excelPath), $sheetIndex);
        $headers = $extracted['headers'];
        $rows = $extracted['rows'];

        $batch = DB::transaction(function () use ($request, $headers, $rows, $sheetIndex, $excelFile, $templateFile, $excelPath, $templatePath): DocumentBatch {
            $batch = DocumentBatch::query()->create([
                'user_id' => $request->user()->id,
                'source_excel_name' => $excelFile->getClientOriginalName(),
                'template_name' => $templateFile->getClientOriginalName(),
                'excel_path' => $excelPath,
                'template_path' => $templatePath,
                'sheet_index' => $sheetIndex,
                'headers_json' => $headers,
                'total_items' => count($rows),
                'status' => count($rows) > 0 ? 'queued' : 'completed',
                'completed_at' => count($rows) > 0 ? null : now(),
            ]);

            if ($rows !== []) {
                $itemPayload = [];
                foreach ($rows as $index => $rowData) {
                    $itemPayload[] = [
                        'document_batch_id' => $batch->id,
                        'row_number' => $index + 2,
                        'row_data' => json_encode($rowData, JSON_THROW_ON_ERROR),
                        'status' => 'queued',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                DocumentBatchItem::query()->insert($itemPayload);
            }

            return $batch;
        });

        DocumentBatchItem::query()
            ->where('document_batch_id', $batch->id)
            ->pluck('id')
            ->each(static function (int $itemId): void {
                GenerateDocumentBatchItemJob::dispatch($itemId);
            });

        return response()->json([
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'total_items' => $batch->total_items,
        ], 201);
    }

    public function progress(Request $request, DocumentBatch $batch): JsonResponse
    {
        $this->assertBatchOwnership($request, $batch);

        return response()->json($this->batchProgressPayload($batch));
    }

    public function items(Request $request, DocumentBatch $batch): JsonResponse
    {
        $this->assertBatchOwnership($request, $batch);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'sort_by' => ['nullable', 'in:row_number,status,created_at,updated_at'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'status' => ['nullable', 'in:queued,processing,docx_done,pdf_done,failed'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);
        $sortBy = (string) ($validated['sort_by'] ?? 'row_number');
        $sortDirection = (string) ($validated['sort_direction'] ?? 'asc');

        $query = DocumentBatchItem::query()->where('document_batch_id', $batch->id);
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $items = $query
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage)
            ->through(static function (DocumentBatchItem $item): array {
                return [
                    'id' => $item->id,
                    'row_number' => $item->row_number,
                    'status' => $item->status,
                    'docx_available' => ! empty($item->docx_path),
                    'pdf_available' => ! empty($item->pdf_path),
                    'error_message' => $item->error_message,
                    'created_at' => $item->created_at?->toISOString(),
                    'updated_at' => $item->updated_at?->toISOString(),
                ];
            });

        return response()->json($items);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'history_per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'sort_by' => ['nullable', 'in:created_at,status,total_items,processed_items'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ]);

        return response()->json($this->historyPayload($request, $validated));
    }

    public function download(
        Request $request,
        DocumentBatch $batch,
        DocumentBatchItem $item,
        string $type
    ): StreamedResponse|BinaryFileResponse {
        $this->assertBatchOwnership($request, $batch);
        if ($item->document_batch_id !== $batch->id) {
            abort(404);
        }

        if (! in_array($type, ['docx', 'pdf'], true)) {
            abort(404);
        }

        $path = $type === 'docx' ? $item->docx_path : $item->pdf_path;
        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        if ($type === 'pdf') {
            return response()->file(Storage::disk('local')->path($path), [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"batch-{$batch->id}-row-{$item->row_number}.pdf\"",
            ]);
        }

        return Storage::disk('local')->download($path, "batch-{$batch->id}-row-{$item->row_number}.docx");
    }

    private function assertBatchOwnership(Request $request, DocumentBatch $batch): void
    {
        abort_unless($batch->user_id === $request->user()->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function historyPayload(Request $request, ?array $validated): array
    {
        $validated ??= [];
        $perPage = (int) ($validated['history_per_page'] ?? $request->integer('history_per_page', 10));
        $perPage = max(5, min($perPage, 100));
        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDirection = (string) ($validated['sort_direction'] ?? 'desc');

        return $request->user()
            ->documentBatches()
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage)
            ->through(function (DocumentBatch $batch): array {
                return [
                    'id' => $batch->id,
                    'source_excel_name' => $batch->source_excel_name,
                    'template_name' => $batch->template_name,
                    'status' => $batch->status,
                    'total_items' => $batch->total_items,
                    'processed_items' => $batch->processed_items,
                    'success_items' => $batch->success_items,
                    'failed_items' => $batch->failed_items,
                    'created_at' => $batch->created_at?->toISOString(),
                    'completed_at' => $batch->completed_at?->toISOString(),
                ];
            })
            ->toArray();
    }

    /**
     * @return array<string, int|string>
     */
    private function batchProgressPayload(DocumentBatch $batch): array
    {
        $total = max(1, $batch->total_items);
        $percent = (int) floor(($batch->processed_items / $total) * 100);

        return [
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'total_items' => $batch->total_items,
            'processed_items' => $batch->processed_items,
            'success_items' => $batch->success_items,
            'failed_items' => $batch->failed_items,
            'progress_percent' => $batch->total_items === 0 ? 100 : min(100, $percent),
        ];
    }
}
