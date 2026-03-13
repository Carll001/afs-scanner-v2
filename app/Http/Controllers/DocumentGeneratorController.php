<?php

namespace App\Http\Controllers;

use App\Http\Requests\DocumentBatchStoreRequest;
use App\Jobs\GenerateDocumentBatchItemJob;
use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\DocumentBatchItemActivityLog;
use App\Models\User;
use App\Services\DocumentBatchActivityLogger;
use App\Services\ExcelExtractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function generatedFiles(Request $request): Response
    {
        return Inertia::render('GeneratedFiles', [
            'initialHistory' => $this->historyPayload($request, null),
        ]);
    }

    public function generatedFilesBatch(Request $request, DocumentBatch $batch): Response
    {
        return Inertia::render('GeneratedBatchItems', [
            'batch' => $this->historyBatchPayload($batch),
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
        return response()->json($this->batchProgressPayload($batch));
    }

    public function items(Request $request, DocumentBatch $batch): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'sort_by' => ['nullable', 'in:row_number,status,created_at,updated_at'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'status' => ['nullable', 'in:queued,processing,docx_done,pdf_done,failed'],
            'company_search' => ['nullable', 'string', 'max:255'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);
        $sortBy = (string) ($validated['sort_by'] ?? 'row_number');
        $sortDirection = (string) ($validated['sort_direction'] ?? 'asc');

        $query = DocumentBatchItem::query()->where('document_batch_id', $batch->id);
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (isset($validated['company_search']) && trim($validated['company_search']) !== '') {
            $this->applyCompanySearch($query, $validated['company_search']);
        }

        $items = $query
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage)
            ->through(static function (DocumentBatchItem $item): array {
                return [
                    'id' => $item->id,
                    'row_number' => $item->row_number,
                    'company' => self::extractCompanyFromRowData($item->row_data ?? []),
                    'status' => $item->status,
                    'row_data' => $item->row_data ?? [],
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
        $this->assertItemBelongsToBatch($batch, $item);

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

    public function showItem(DocumentBatch $batch, DocumentBatchItem $item): JsonResponse
    {
        $this->assertItemBelongsToBatch($batch, $item);

        return response()->json($this->batchItemPayload($item));
    }

    public function updateItem(
        Request $request,
        DocumentBatch $batch,
        DocumentBatchItem $item,
        DocumentBatchActivityLogger $activityLogger
    ): JsonResponse {
        $this->assertItemBelongsToBatch($batch, $item);

        $validated = $request->validate([
            'row_data' => ['required', 'array', 'min:1'],
            'row_data.*' => ['nullable', 'string'],
        ]);

        /** @var array<string, string|null> $submittedRowData */
        $submittedRowData = $validated['row_data'];
        $existingRowData = $item->row_data ?? [];
        $updatedRowData = [];

        foreach ($existingRowData as $key => $value) {
            $updatedRowData[$key] = (string) ($submittedRowData[$key] ?? '');
        }

        foreach ($submittedRowData as $key => $value) {
            if (! array_key_exists($key, $updatedRowData)) {
                $updatedRowData[$key] = (string) ($value ?? '');
            }
        }

        DB::transaction(function () use ($request, $batch, $item, $updatedRowData, $existingRowData, $activityLogger): void {
            $lockedItem = DocumentBatchItem::query()->lockForUpdate()->findOrFail($item->id);
            $lockedBatch = DocumentBatch::query()->lockForUpdate()->findOrFail($batch->id);

            $oldDocxPath = $lockedItem->docx_path;
            $oldPdfPath = $lockedItem->pdf_path;
            $previousStatus = $lockedItem->status;

            if ($lockedItem->completed_at !== null) {
                $lockedBatch->processed_items = max(0, $lockedBatch->processed_items - 1);
            }
            if ($previousStatus === 'pdf_done') {
                $lockedBatch->success_items = max(0, $lockedBatch->success_items - 1);
            }
            if ($previousStatus === 'failed') {
                $lockedBatch->failed_items = max(0, $lockedBatch->failed_items - 1);
            }

            $lockedItem->row_data = $updatedRowData;
            $lockedItem->status = 'queued';
            $lockedItem->docx_path = null;
            $lockedItem->pdf_path = null;
            $lockedItem->error_message = null;
            $lockedItem->started_at = null;
            $lockedItem->completed_at = null;
            $lockedItem->save();

            $lockedBatch->status = $lockedBatch->total_items > 0 ? 'queued' : 'completed';
            $lockedBatch->started_at = null;
            $lockedBatch->completed_at = null;
            $lockedBatch->save();

            foreach ([$oldDocxPath, $oldPdfPath] as $path) {
                if (is_string($path) && $path !== '' && Storage::disk('local')->exists($path)) {
                    Storage::disk('local')->delete($path);
                }
            }

            /** @var User|null $actor */
            $actor = $request->user();
            $activityLogger->log(
                $lockedBatch,
                $lockedItem,
                $actor,
                'row_updated',
                "Row {$lockedItem->row_number} was edited and queued for regeneration.",
                [
                    'before' => $existingRowData,
                    'after' => $updatedRowData,
                    'previous_status' => $previousStatus,
                ]
            );

            if ($oldDocxPath || $oldPdfPath) {
                $activityLogger->log(
                    $lockedBatch,
                    $lockedItem,
                    $actor,
                    'old_outputs_deleted',
                    "Old generated files for row {$lockedItem->row_number} were deleted.",
                    [
                        'docx_path' => $oldDocxPath,
                        'pdf_path' => $oldPdfPath,
                    ]
                );
            }

            $activityLogger->log(
                $lockedBatch,
                $lockedItem,
                $actor,
                'regeneration_requested',
                "Regeneration requested for row {$lockedItem->row_number}.",
                [
                    'status' => 'queued',
                ]
            );
        });

        GenerateDocumentBatchItemJob::dispatch($item->id);

        $refreshedItem = DocumentBatchItem::query()->findOrFail($item->id);

        return response()->json($this->batchItemPayload($refreshedItem));
    }

    public function logs(Request $request, DocumentBatch $batch): JsonResponse
    {
        if (! Schema::hasTable('document_batch_item_activity_logs')) {
            return response()->json([
                'current_page' => 1,
                'data' => [],
                'last_page' => 1,
                'per_page' => 10,
                'total' => 0,
            ]);
        }

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);

        $logs = DocumentBatchItemActivityLog::query()
            ->with(['item:id,row_number', 'user:id,name'])
            ->where('document_batch_id', $batch->id)
            ->latest()
            ->paginate($perPage)
            ->through(static function (DocumentBatchItemActivityLog $log): array {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'summary' => $log->summary,
                    'details' => $log->details ?? [],
                    'created_at' => $log->created_at?->toISOString(),
                    'row_number' => $log->item?->row_number,
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                    ] : null,
                ];
            });

        return response()->json($logs);
    }

    private function assertBatchOwnership(Request $request, DocumentBatch $batch): void
    {
        abort_unless($batch->user_id === $request->user()->id, 404);
    }

    private function assertItemBelongsToBatch(DocumentBatch $batch, DocumentBatchItem $item): void
    {
        abort_unless($item->document_batch_id === $batch->id, 404);
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
            ->through(fn (DocumentBatch $batch): array => $this->historyBatchPayload($batch))
            ->toArray();
    }

    /**
     * @return array<string, int|string|null>
     */
    private function historyBatchPayload(DocumentBatch $batch): array
    {
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

    /**
     * @return array<string, mixed>
     */
    private function batchItemPayload(DocumentBatchItem $item): array
    {
        return [
            'id' => $item->id,
            'row_number' => $item->row_number,
            'company' => self::extractCompanyFromRowData($item->row_data ?? []),
            'status' => $item->status,
            'row_data' => $item->row_data ?? [],
            'docx_available' => ! empty($item->docx_path),
            'pdf_available' => ! empty($item->pdf_path),
            'error_message' => $item->error_message,
            'created_at' => $item->created_at?->toISOString(),
            'updated_at' => $item->updated_at?->toISOString(),
        ];
    }

    private function applyCompanySearch(Builder $query, string $companySearch): void
    {
        $search = '%'.mb_strtolower(trim($companySearch)).'%';
        $driver = $query->getModel()->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $query->whereRaw(
                "exists (
                    select 1
                    from jsonb_each_text(row_data::jsonb) as company_entry(key, value)
                    where lower(company_entry.key) like ?
                    and lower(company_entry.value) like ?
                )",
                ['%company%', $search]
            );

            return;
        }

        $query->whereRaw('LOWER(CAST(row_data AS CHAR)) LIKE ?', [$search]);
    }

    /**
     * @param  array<string, mixed>  $rowData
     */
    private static function extractCompanyFromRowData(array $rowData): string
    {
        $fallback = '';

        foreach ($rowData as $key => $value) {
            $normalizedKey = self::normalizeCompanyKey((string) $key);
            $stringValue = is_scalar($value) ? trim((string) $value) : '';

            if ($normalizedKey === 'company') {
                return $stringValue;
            }

            if ($fallback === '' && str_contains($normalizedKey, 'company')) {
                $fallback = $stringValue;
            }
        }

        return $fallback;
    }

    private static function normalizeCompanyKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($key)) ?? '';
    }
}
