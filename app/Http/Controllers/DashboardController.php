<?php

namespace App\Http\Controllers;

use App\Models\DocumentBatch;
use App\Models\DocumentGeneratorTemplate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $recentBatches = $user->documentBatches()
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (DocumentBatch $batch): array => [
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
            ])
            ->all();

        $totalBatches = $user->documentBatches()->count();
        $activeBatches = $user->documentBatches()->whereIn('status', ['queued', 'processing'])->count();
        $completedBatches = $user->documentBatches()->where('status', 'completed')->count();
        $failedBatches = $user->documentBatches()->where('status', 'failed')->count();
        $totalGeneratedFiles = (int) $user->documentBatches()->sum('success_items') * 2;

        $templates = DocumentGeneratorTemplate::query()
            ->orderByRaw('case when year is null then 0 else 1 end')
            ->orderBy('year')
            ->get();

        $defaultTemplate = $templates->first(static fn (DocumentGeneratorTemplate $template): bool => $template->year === null);
        $yearTemplates = $templates
            ->filter(static fn (DocumentGeneratorTemplate $template): bool => $template->year !== null)
            ->values();

        return Inertia::render('Dashboard', [
            'stats' => [
                'total_batches' => $totalBatches,
                'active_batches' => $activeBatches,
                'completed_batches' => $completedBatches,
                'failed_batches' => $failedBatches,
                'total_generated_files' => $totalGeneratedFiles,
            ],
            'recent_batches' => $recentBatches,
            'template_summary' => [
                'default_template_present' => $defaultTemplate !== null,
                'default_template_name' => $defaultTemplate?->template_name,
                'year_rule_count' => $yearTemplates->count(),
            ],
        ]);
    }
}
