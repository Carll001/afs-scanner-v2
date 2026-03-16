<?php

namespace App\Http\Controllers;

use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\DocumentBatchItemActivityLog;
use App\Models\User;
use App\Support\DocumentBatchItemData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $recentWindowStart = now()->subDays(30);
        $userBatchIds = $user->documentBatches()->select('id');
        $activeStatuses = ['queued', 'processing'];
        $activeBatchesScope = $user->documentBatches()->whereIn('status', $activeStatuses);

        $activeBatchCount = (clone $activeBatchesScope)->count();
        $activeTotalItems = (clone $activeBatchesScope)->sum('total_items');
        $activeProcessedItems = (clone $activeBatchesScope)->sum('processed_items');

        $processedItems30d = (int) $user->documentBatches()
            ->where('created_at', '>=', $recentWindowStart)
            ->sum('processed_items');

        $successfulItems30d = (int) $user->documentBatches()
            ->where('created_at', '>=', $recentWindowStart)
            ->sum('success_items');

        return Inertia::render('Dashboard', [
            'summary' => [
                'active_batches' => (int) $activeBatchCount,
                'rows_in_progress' => max(0, (int) $activeTotalItems - (int) $activeProcessedItems),
                'documents_generated_30d' => $successfulItems30d,
                'failed_rows_open' => (int) DocumentBatchItem::query()
                    ->where('status', 'failed')
                    ->whereIn('document_batch_id', $userBatchIds)
                    ->count(),
                'success_rate_30d' => $processedItems30d > 0
                    ? (int) round(($successfulItems30d / $processedItems30d) * 100)
                    : 0,
            ],
            'active_batches' => (clone $activeBatchesScope)
                ->latest()
                ->limit(3)
                ->get()
                ->map(fn (DocumentBatch $batch): array => $this->batchPayload($batch))
                ->values()
                ->all(),
            'recent_failures' => DocumentBatchItem::query()
                ->where('status', 'failed')
                ->whereIn('document_batch_id', $userBatchIds)
                ->latest('updated_at')
                ->limit(5)
                ->get()
                ->map(static function (DocumentBatchItem $item): array {
                    return [
                        'batch_id' => $item->document_batch_id,
                        'item_id' => $item->id,
                        'row_number' => $item->row_number,
                        'company' => DocumentBatchItemData::extractCompany($item->row_data ?? []),
                        'error_message' => $item->error_message ?? 'Unknown generation error.',
                        'updated_at' => $item->updated_at?->toISOString(),
                    ];
                })
                ->values()
                ->all(),
            'recent_activity' => DocumentBatchItemActivityLog::query()
                ->with(['item:id,row_number', 'user:id,name'])
                ->whereIn('document_batch_id', $userBatchIds)
                ->latest()
                ->limit(8)
                ->get()
                ->map(static function (DocumentBatchItemActivityLog $log): array {
                    return [
                        'id' => $log->id,
                        'batch_id' => $log->document_batch_id,
                        'row_number' => $log->item?->row_number,
                        'action' => $log->action,
                        'summary' => $log->summary,
                        'user_name' => $log->user?->name ?? 'System',
                        'created_at' => $log->created_at?->toISOString(),
                    ];
                })
                ->values()
                ->all(),
            'recent_batches' => $user->documentBatches()
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (DocumentBatch $batch): array => $this->batchPayload($batch))
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function batchPayload(DocumentBatch $batch): array
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
}
