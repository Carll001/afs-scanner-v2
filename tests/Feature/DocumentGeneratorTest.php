<?php

namespace Tests\Feature;

use App\Jobs\GenerateDocumentBatchItemJob;
use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\User;
use App\Services\DocxTemplateService;
use App\Services\ExcelExtractionService;
use App\Services\PdfConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class DocumentGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_document_generator_routes(): void
    {
        $this->get(route('document-generator.index'))->assertRedirect(route('login'));
        $this->post(route('document-generator.batches.store'))->assertRedirect(route('login'));
    }

    public function test_batch_creation_validates_required_files(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->postJson(route('document-generator.batches.store'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['excel_file', 'template_file']);
    }

    public function test_batch_creation_dispatches_jobs_for_each_excel_row(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->mock(ExcelExtractionService::class, function ($mock): void {
            $mock
                ->shouldReceive('extract')
                ->once()
                ->andReturn([
                    'headers' => ['Name', 'Email'],
                    'rows' => [
                        ['Name' => 'Jane', 'Email' => 'jane@example.com'],
                        ['Name' => 'John', 'Email' => 'john@example.com'],
                    ],
                ]);
        });

        $response = $this->postJson(route('document-generator.batches.store'), [
            'excel_file' => UploadedFile::fake()->create('source.xlsx', 20),
            'template_file' => UploadedFile::fake()->create('template.docx', 20),
            'sheet_index' => 0,
        ]);

        $response->assertCreated()->assertJsonStructure(['batch_id', 'status', 'total_items']);

        $this->assertDatabaseHas('document_batches', [
            'user_id' => $user->id,
            'total_items' => 2,
            'status' => 'queued',
        ]);

        $this->assertDatabaseCount('document_batch_items', 2);
        Queue::assertPushed(GenerateDocumentBatchItemJob::class, 2);
    }

    public function test_any_authenticated_user_can_view_batch_progress_items_logs_and_downloads(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $viewer = User::factory()->create();

        $batch = DocumentBatch::factory()->for($owner)->create([
            'status' => 'processing',
            'total_items' => 1,
            'processed_items' => 0,
        ]);

        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'pdf_done',
            'docx_path' => "document-generator/{$owner->id}/batch-{$batch->id}/row-2.docx",
            'pdf_path' => "document-generator/{$owner->id}/batch-{$batch->id}/row-2.pdf",
        ]);

        Storage::disk('local')->put($item->docx_path, 'docx-content');
        Storage::disk('local')->put($item->pdf_path, 'pdf-content');

        $item->activityLogs()->create([
            'document_batch_id' => $batch->id,
            'user_id' => $owner->id,
            'action' => 'generation_completed',
            'summary' => 'Row generated.',
            'details' => ['row_number' => 2],
        ]);

        $this->actingAs($viewer);

        $this->getJson(route('document-generator.batches.progress', $batch))
            ->assertOk()
            ->assertJsonPath('batch_id', $batch->id);

        $this->getJson(route('document-generator.batches.items', $batch))
            ->assertOk()
            ->assertJsonPath('data.0.id', $item->id);

        $this->getJson(route('document-generator.batches.logs', $batch))
            ->assertOk()
            ->assertJsonPath('data.0.action', 'generation_completed');

        $this->get(route('document-generator.batches.items.download', [$batch, $item, 'pdf']))
            ->assertOk();
    }

    public function test_job_allows_generation_when_placeholder_has_no_matching_header(): void
    {
        Storage::fake('local');

        $batch = $this->createBatchWithTemplate();
        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_number' => 2,
            'row_data' => ['Name' => 'Jane'],
            'status' => 'queued',
        ]);

        $docxService = Mockery::mock(DocxTemplateService::class);
        $docxService->shouldReceive('validateRowData')
            ->once()
            ->andReturn([
                'missing_data' => [],
            ]);
        $docxService->shouldReceive('render')
            ->once()
            ->andReturnUsing(function (string $templatePath, string $outputPath): void {
                file_put_contents($outputPath, 'docx-content');
            });

        $pdfService = Mockery::mock(PdfConversionService::class);
        $pdfService->shouldReceive('convertDocxToPdf')
            ->once()
            ->andReturnUsing(function (string $docxPath): string {
                $pdfPath = preg_replace('/\.docx$/', '.pdf', $docxPath);
                file_put_contents((string) $pdfPath, 'pdf-content');

                return (string) $pdfPath;
            });

        (new GenerateDocumentBatchItemJob($item->id))->handle(
            app(\App\Services\DocumentBatchActivityLogger::class),
            $docxService,
            $pdfService,
        );

        $item->refresh();
        $batch->refresh();

        $this->assertSame('pdf_done', $item->status);
        $this->assertNull($item->error_message);
        $this->assertNotNull($item->docx_path);
        $this->assertNotNull($item->pdf_path);
        $this->assertSame(1, $batch->processed_items);
        $this->assertSame(1, $batch->success_items);
        $this->assertSame(0, $batch->failed_items);
        $this->assertSame('completed', $batch->status);
        $this->assertDatabaseHas('document_batch_item_activity_logs', [
            'document_batch_item_id' => $item->id,
            'action' => 'generation_completed',
        ]);
    }

    public function test_job_marks_item_failed_when_template_placeholder_value_is_blank(): void
    {
        Storage::fake('local');

        $batch = $this->createBatchWithTemplate();
        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_number' => 2,
            'row_data' => ['inn' => ''],
            'status' => 'queued',
        ]);

        $docxService = Mockery::mock(DocxTemplateService::class);
        $docxService->shouldReceive('validateRowData')
            ->once()
            ->andReturn([
                'missing_data' => ['inn'],
            ]);
        $docxService->shouldNotReceive('render');

        $pdfService = Mockery::mock(PdfConversionService::class);
        $pdfService->shouldNotReceive('convertDocxToPdf');

        (new GenerateDocumentBatchItemJob($item->id))->handle(
            app(\App\Services\DocumentBatchActivityLogger::class),
            $docxService,
            $pdfService,
        );

        $item->refresh();

        $this->assertSame('failed', $item->status);
        $this->assertStringContainsString('Missing data: inn', (string) $item->error_message);
        $this->assertDatabaseHas('document_batch_item_activity_logs', [
            'document_batch_item_id' => $item->id,
            'action' => 'generation_failed_validation',
        ]);
    }

    public function test_editing_a_failed_row_regenerates_it_and_records_logs(): void
    {
        Storage::fake('local');
        Queue::fake();

        $owner = User::factory()->create();
        $editor = User::factory()->create();

        $batch = $this->createBatchWithTemplate($owner, [
            'status' => 'failed',
            'total_items' => 1,
            'processed_items' => 1,
            'failed_items' => 1,
            'completed_at' => now(),
        ]);

        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_number' => 2,
            'row_data' => ['inn' => ''],
            'status' => 'failed',
            'error_message' => 'Blank values: inn',
            'completed_at' => now(),
        ]);

        $this->actingAs($editor)
            ->putJson(route('document-generator.batches.items.update', [$batch, $item]), [
                'row_data' => ['inn' => '123456'],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('row_data.inn', '123456');

        Queue::assertPushed(GenerateDocumentBatchItemJob::class, 1);

        $item->refresh();
        $batch->refresh();

        $this->assertSame('queued', $item->status);
        $this->assertNull($item->error_message);
        $this->assertSame(0, $batch->processed_items);
        $this->assertSame(0, $batch->failed_items);
        $this->assertSame('queued', $batch->status);

        $docxService = Mockery::mock(DocxTemplateService::class);
        $docxService->shouldReceive('validateRowData')
            ->once()
            ->andReturn([
                'missing_data' => [],
            ]);
        $docxService->shouldReceive('render')
            ->once()
            ->andReturnUsing(function (string $templatePath, string $outputPath): void {
                file_put_contents($outputPath, 'docx-content');
            });

        $pdfService = Mockery::mock(PdfConversionService::class);
        $pdfService->shouldReceive('convertDocxToPdf')
            ->once()
            ->andReturnUsing(function (string $docxPath): string {
                $pdfPath = preg_replace('/\.docx$/', '.pdf', $docxPath);
                file_put_contents((string) $pdfPath, 'pdf-content');

                return (string) $pdfPath;
            });

        (new GenerateDocumentBatchItemJob($item->id))->handle(
            app(\App\Services\DocumentBatchActivityLogger::class),
            $docxService,
            $pdfService,
        );

        $item->refresh();

        $this->assertSame('pdf_done', $item->status);
        $this->assertNotNull($item->docx_path);
        $this->assertNotNull($item->pdf_path);
        $this->assertDatabaseHas('document_batch_item_activity_logs', [
            'document_batch_item_id' => $item->id,
            'action' => 'row_updated',
            'user_id' => $editor->id,
        ]);
        $this->assertDatabaseHas('document_batch_item_activity_logs', [
            'document_batch_item_id' => $item->id,
            'action' => 'regeneration_requested',
            'user_id' => $editor->id,
        ]);
        $this->assertDatabaseHas('document_batch_item_activity_logs', [
            'document_batch_item_id' => $item->id,
            'action' => 'generation_completed',
        ]);
    }

    public function test_editing_a_successful_row_deletes_old_outputs_and_creates_audit_entries(): void
    {
        Storage::fake('local');
        Queue::fake();

        $owner = User::factory()->create();
        $editor = User::factory()->create();

        $batch = $this->createBatchWithTemplate($owner, [
            'status' => 'completed',
            'total_items' => 1,
            'processed_items' => 1,
            'success_items' => 1,
            'completed_at' => now(),
        ]);

        $oldDocxPath = "document-generator/{$owner->id}/batch-{$batch->id}/row-2.docx";
        $oldPdfPath = "document-generator/{$owner->id}/batch-{$batch->id}/row-2.pdf";

        Storage::disk('local')->put($oldDocxPath, 'old-docx');
        Storage::disk('local')->put($oldPdfPath, 'old-pdf');

        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_number' => 2,
            'row_data' => ['inn' => '111'],
            'status' => 'pdf_done',
            'docx_path' => $oldDocxPath,
            'pdf_path' => $oldPdfPath,
            'completed_at' => now(),
        ]);

        $this->actingAs($editor)
            ->putJson(route('document-generator.batches.items.update', [$batch, $item]), [
                'row_data' => ['inn' => '222'],
            ])
            ->assertOk()
            ->assertJsonPath('row_data.inn', '222');

        Storage::disk('local')->assertMissing($oldDocxPath);
        Storage::disk('local')->assertMissing($oldPdfPath);

        $item->refresh();
        $batch->refresh();

        $this->assertSame('queued', $item->status);
        $this->assertNull($item->docx_path);
        $this->assertNull($item->pdf_path);
        $this->assertSame(0, $batch->processed_items);
        $this->assertSame(0, $batch->success_items);
        $this->assertDatabaseHas('document_batch_item_activity_logs', [
            'document_batch_item_id' => $item->id,
            'action' => 'old_outputs_deleted',
            'user_id' => $editor->id,
        ]);
    }

    public function test_activity_log_endpoint_returns_entries_in_reverse_chronological_order(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $batch = DocumentBatch::factory()->for($user)->create();
        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
        ]);

        $item->activityLogs()->create([
            'document_batch_id' => $batch->id,
            'user_id' => $user->id,
            'action' => 'row_updated',
            'summary' => 'First',
            'details' => [],
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $item->activityLogs()->create([
            'document_batch_id' => $batch->id,
            'user_id' => $user->id,
            'action' => 'generation_completed',
            'summary' => 'Second',
            'details' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson(route('document-generator.batches.logs', $batch))
            ->assertOk()
            ->assertJsonPath('data.0.action', 'generation_completed')
            ->assertJsonPath('data.0.user.name', $user->name)
            ->assertJsonPath('data.0.row_number', $item->row_number)
            ->assertJsonPath('data.1.action', 'row_updated');
    }

    private function createBatchWithTemplate(?User $owner = null, array $attributes = []): DocumentBatch
    {
        Storage::disk('local')->put('document-generator/template.docx', 'template-content');

        return DocumentBatch::factory()->for($owner ?? User::factory())->create(array_merge([
            'template_path' => 'document-generator/template.docx',
            'status' => 'queued',
            'total_items' => 1,
            'processed_items' => 0,
            'success_items' => 0,
            'failed_items' => 0,
        ], $attributes));
    }
}
