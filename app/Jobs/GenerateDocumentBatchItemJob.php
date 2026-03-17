<?php

namespace App\Jobs;

use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\DocumentBatchTemplate;
use App\Models\User;
use App\Services\DocumentBatchActivityLogger;
use App\Services\DocxTemplateService;
use App\Services\PdfConversionService;
use Carbon\CarbonImmutable;
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
        $item = DocumentBatchItem::with('batch.templates')->find($this->documentBatchItemId);
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

            /** @var array<string, string> $rowData */
            $rowData = $item->row_data ?? [];
            $templatePath = $this->resolveTemplatePath($batch, $rowData);
            $docxPath = Storage::disk('local')->path($docxRelativePath);

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

    /**
     * @param array<string, string> $rowData
     */
    private function resolveTemplatePath(DocumentBatch $batch, array $rowData): string
    {
        $year = $this->extractRegistrationYear($rowData);
        if ($year === null) {
            throw new \RuntimeException(
                'Invalid SEC REGISTRATION DATE. Expected a recognizable date such as 7/23/2024 00:00:00.'
            );
        }

        $template = $this->resolveTemplate($batch, $year);
        if (! $template instanceof DocumentBatchTemplate) {
            throw new \RuntimeException("No template configured for year {$year}.");
        }

        if (! Storage::disk('local')->exists($template->template_path)) {
            throw new \RuntimeException("Template file is missing for year {$year}.");
        }

        return Storage::disk('local')->path($template->template_path);
    }

    private function resolveTemplate(DocumentBatch $batch, int $rowYear): ?DocumentBatchTemplate
    {
        /** @var \Illuminate\Support\Collection<int, DocumentBatchTemplate> $templates */
        $templates = $batch->templates->sortByDesc(static fn (DocumentBatchTemplate $template): int => $template->year ?? -1);

        $yearTemplate = $templates
            ->filter(static fn (DocumentBatchTemplate $template): bool => $template->year !== null)
            ->first(static fn (DocumentBatchTemplate $template): bool => (int) $template->year <= $rowYear);

        if ($yearTemplate instanceof DocumentBatchTemplate) {
            return $yearTemplate;
        }

        return $templates->first(static fn (DocumentBatchTemplate $template): bool => $template->year === null);
    }

    /**
     * @param array<string, string> $rowData
     */
    private function extractRegistrationYear(array $rowData): ?int
    {
        foreach ($rowData as $header => $value) {
            if ($this->normalizeHeader($header) !== 'sec_registration_date') {
                continue;
            }

            $normalizedValue = trim($value);
            if ($normalizedValue === '') {
                return null;
            }

            $year = $this->extractYearFromSupportedDateFormats($normalizedValue);
            if ($year !== null) {
                return $year;
            }

            if (preg_match('/\b(\d{4})\b/', $normalizedValue, $matches) === 1) {
                return (int) $matches[1];
            }

            return null;
        }

        return null;
    }

    private function extractYearFromSupportedDateFormats(string $value): ?int
    {
        $formats = [
            'n/j/Y G:i',
            'n/j/Y H:i',
            'n/j/Y G:i:s',
            'n/j/Y H:i:s',
            'm/d/Y G:i',
            'm/d/Y H:i',
            'm/d/Y G:i:s',
            'm/d/Y H:i:s',
            'n-d-Y G:i',
            'n-d-Y H:i',
            'n-d-Y G:i:s',
            'n-d-Y H:i:s',
            'm-d-Y G:i',
            'm-d-Y H:i',
            'm-d-Y G:i:s',
            'm-d-Y H:i:s',
            'n.j.Y G:i',
            'n.j.Y H:i',
            'n.j.Y G:i:s',
            'n.j.Y H:i:s',
            'm.d.Y G:i',
            'm.d.Y H:i',
            'm.d.Y G:i:s',
            'm.d.Y H:i:s',
            'Y-m-d',
            'Y-m-d H:i',
            'Y-m-d H:i:s',
            'Y-m-d G:i',
            'Y-m-d G:i:s',
            'Y/m/d',
            'Y/m/d H:i',
            'Y/m/d H:i:s',
            'Y/m/d G:i',
            'Y/m/d G:i:s',
            'Y.m.d',
            'Y.m.d H:i',
            'Y.m.d H:i:s',
            'Y.m.d G:i',
            'Y.m.d G:i:s',
            'Y-n-j',
            'Y-n-j H:i',
            'Y-n-j H:i:s',
            'Y-n-j G:i',
            'Y-n-j G:i:s',
            'Y/n/j',
            'Y/n/j H:i',
            'Y/n/j H:i:s',
            'Y/n/j G:i',
            'Y/n/j G:i:s',
            'Y.n.j',
            'Y.n.j H:i',
            'Y.n.j H:i:s',
            'Y.n.j G:i',
            'Y.n.j G:i:s',
            'm/d/y G:i',
            'm/d/y H:i',
            'm/d/y G:i:s',
            'm/d/y H:i:s',
            'm-d-y G:i',
            'm-d-y H:i',
            'm-d-y G:i:s',
            'm-d-y H:i:s',
            'm.d.y G:i',
            'm.d.y H:i',
            'm.d.y G:i:s',
            'm.d.y H:i:s',
            'n/j/y G:i',
            'n/j/y H:i',
            'n/j/y G:i:s',
            'n/j/y H:i:s',
            'n-d-y G:i',
            'n-d-y H:i',
            'n-d-y G:i:s',
            'n-d-y H:i:s',
            'n.d.y G:i',
            'n.d.y H:i',
            'n.d.y G:i:s',
            'n.d.y H:i:s',
        ];

        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = \DateTimeImmutable::getLastErrors();

            if (
                $date instanceof \DateTimeImmutable
                && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
            ) {
                return (int) $date->format('Y');
            }
        }

        try {
            return CarbonImmutable::parse($value)->year;
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeHeader(string $header): string
    {
        $normalized = mb_strtolower(trim($header));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
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
