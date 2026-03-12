<?php

namespace App\Jobs;

use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\User;
use App\Services\DocumentBatchActivityLogger;
use App\Services\DocxTemplateService;
use App\Services\PdfConversionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateDocumentBatchItemJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $documentBatchItemId
    ) {}

    public function handle(
        DocumentBatchActivityLogger $activityLogger,
        DocxTemplateService $docxTemplateService,
        PdfConversionService $pdfConversionService
    ): void {
        $item = DocumentBatchItem::with('batch')->find($this->documentBatchItemId);
        if (! $item instanceof DocumentBatchItem) {
            return;
        }

        if (in_array($item->status, ['pdf_done', 'failed'], true)) {
            return;
        }

        $this->markItemProcessing($item->id);

        try {
            $batch = $item->batch;
            if (! $batch instanceof DocumentBatch) {
                throw new \RuntimeException('Document batch not found.');
            }

            $baseDir = "document-generator/{$batch->user_id}/batch-{$batch->id}";
            $docxRelativePath = "{$baseDir}/row-{$item->row_number}.docx";
            $pdfRelativePath = "{$baseDir}/row-{$item->row_number}.pdf";

            Storage::disk('local')->makeDirectory($baseDir);

            $templatePath = Storage::disk('local')->path($batch->template_path);
            $docxPath = Storage::disk('local')->path($docxRelativePath);

            /** @var array<string, string> $rowData */
            $rowData = $item->row_data ?? [];
            $validation = $docxTemplateService->validateRowData($templatePath, $rowData);
            if ($validation['missing_data'] !== []) {
                $errorMessage = 'Missing data: '.implode(', ', $validation['missing_data']);
                $this->markItemFinal($item->id, false, null, null, $errorMessage);
                $failedItem = DocumentBatchItem::query()->find($item->id);

                if ($failedItem instanceof DocumentBatchItem) {
                    $activityLogger->log(
                        $batch,
                        $failedItem,
                        null,
                        'generation_failed_validation',
                        "Row {$item->row_number} failed placeholder validation.",
                        $validation
                    );
                }

                return;
            }

            $docxTemplateService->render($templatePath, $docxPath, $rowData);

            $this->markDocxDone($item->id, $docxRelativePath);

            $pdfAbsolutePath = $pdfConversionService->convertDocxToPdf($docxPath);
            $storedPdfPath = $this->storePdfAsExpectedPath($pdfAbsolutePath, $pdfRelativePath);

            $this->markItemFinal($item->id, true, $docxRelativePath, $storedPdfPath);
            $completedItem = DocumentBatchItem::query()->find($item->id);
            if ($completedItem instanceof DocumentBatchItem) {
                $activityLogger->log(
                    $batch,
                    $completedItem,
                    null,
                    'generation_completed',
                    "Row {$item->row_number} generated successfully.",
                    [
                        'docx_path' => $docxRelativePath,
                        'pdf_path' => $storedPdfPath,
                    ]
                );
            }
        } catch (Throwable $exception) {
            $this->markItemFinal(
                $item->id,
                false,
                $item->docx_path,
                null,
                mb_substr($exception->getMessage(), 0, 2000)
            );

            $batch = $item->batch;
            if ($batch instanceof DocumentBatch) {
                $failedItem = DocumentBatchItem::query()->find($item->id);
                if ($failedItem instanceof DocumentBatchItem) {
                    $activityLogger->log(
                        $batch,
                        $failedItem,
                        null,
                        'generation_failed',
                        "Row {$item->row_number} generation failed.",
                        [
                            'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                        ]
                    );
                }
            }
        }
    }

    private function storePdfAsExpectedPath(string $absolutePdfPath, string $expectedRelativePath): string
    {
        $absoluteExpectedPath = Storage::disk('local')->path($expectedRelativePath);
        if ($absolutePdfPath !== $absoluteExpectedPath && file_exists($absolutePdfPath)) {
            @rename($absolutePdfPath, $absoluteExpectedPath);
        }

        return $expectedRelativePath;
    }

    private function markItemProcessing(int $itemId): void
    {
        DB::transaction(function () use ($itemId): void {
            $item = DocumentBatchItem::query()->lockForUpdate()->find($itemId);
            if (! $item instanceof DocumentBatchItem) {
                return;
            }

            if (in_array($item->status, ['pdf_done', 'failed'], true)) {
                return;
            }

            if ($item->status !== 'processing') {
                $item->status = 'processing';
                $item->started_at = $item->started_at ?? now();
                $item->save();
            }

            $batch = DocumentBatch::query()->lockForUpdate()->find($item->document_batch_id);
            if ($batch instanceof DocumentBatch && $batch->status === 'queued') {
                $batch->status = 'processing';
                $batch->started_at = $batch->started_at ?? now();
                $batch->save();
            }
        });
    }

    private function markDocxDone(int $itemId, string $docxPath): void
    {
        DB::transaction(function () use ($itemId, $docxPath): void {
            $item = DocumentBatchItem::query()->lockForUpdate()->find($itemId);
            if (! $item instanceof DocumentBatchItem) {
                return;
            }

            if (in_array($item->status, ['pdf_done', 'failed'], true)) {
                return;
            }

            $item->status = 'docx_done';
            $item->docx_path = $docxPath;
            $item->save();
        });
    }

    private function markItemFinal(
        int $itemId,
        bool $isSuccess,
        ?string $docxPath,
        ?string $pdfPath,
        ?string $errorMessage = null
    ): void {
        DB::transaction(function () use ($itemId, $isSuccess, $docxPath, $pdfPath, $errorMessage): void {
            $item = DocumentBatchItem::query()->lockForUpdate()->find($itemId);
            if (! $item instanceof DocumentBatchItem) {
                return;
            }

            if (in_array($item->status, ['pdf_done', 'failed'], true)) {
                return;
            }

            $item->status = $isSuccess ? 'pdf_done' : 'failed';
            $item->docx_path = $docxPath;
            $item->pdf_path = $pdfPath;
            $item->error_message = $errorMessage;
            $item->completed_at = now();
            $item->save();

            $batch = DocumentBatch::query()->lockForUpdate()->find($item->document_batch_id);
            if (! $batch instanceof DocumentBatch) {
                return;
            }

            $batch->processed_items++;
            if ($isSuccess) {
                $batch->success_items++;
            } else {
                $batch->failed_items++;
            }

            $isComplete = $batch->processed_items >= $batch->total_items;
            if ($isComplete) {
                $batch->status = $batch->failed_items > 0 ? 'failed' : 'completed';
                $batch->completed_at = now();
            } else {
                $batch->status = 'processing';
                $batch->started_at = $batch->started_at ?? now();
            }

            $batch->save();
        });
    }
}
