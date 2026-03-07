<?php

namespace Tests\Feature;

use App\Jobs\GenerateDocumentBatchItemJob;
use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\User;
use App\Services\ExcelExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    public function test_progress_items_and_download_are_owner_protected(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

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

        $this->actingAs($otherUser);
        $this->getJson(route('document-generator.batches.progress', $batch))->assertNotFound();
        $this->getJson(route('document-generator.batches.items', $batch))->assertNotFound();
        $this->get(route('document-generator.batches.items.download', [$batch, $item, 'pdf']))->assertNotFound();

        $this->actingAs($owner);
        $this->getJson(route('document-generator.batches.progress', $batch))
            ->assertOk()
            ->assertJsonStructure([
                'batch_id',
                'status',
                'total_items',
                'processed_items',
                'success_items',
                'failed_items',
                'progress_percent',
            ]);

        $this->getJson(route('document-generator.batches.items', $batch))
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'last_page', 'per_page', 'total']);

        $this->get(route('document-generator.batches.items.download', [$batch, $item, 'pdf']))
            ->assertOk();
    }
}
