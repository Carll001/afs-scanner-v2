<?php

namespace Tests\Feature;

use App\Jobs\GenerateDocumentBatchItemJob;
use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\DocumentBatchTemplate;
use App\Models\DocumentGeneratorTemplate;
use App\Models\User;
use App\Services\DocxTemplateService;
use App\Services\ExcelExtractionService;
use App\Services\PdfConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;
use ZipArchive;

class DocumentGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_document_generator_routes(): void
    {
        $this->get(route('document-generator.index'))->assertRedirect(route('login'));
        $this->get(route('generated-files.index'))->assertRedirect(route('login'));
        $this->post(route('document-generator.batches.store'))->assertRedirect(route('login'));
    }

    public function test_batch_creation_validates_required_files(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->postJson(route('document-generator.batches.store'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['excel_file', 'default_template_file']);
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
                ->with(Mockery::type('string'), 0)
                ->andReturn([
                    'headers' => ['Name', 'Email', 'SEC REGISTRATION DATE'],
                    'rows' => [
                        ['Name' => 'Jane', 'Email' => 'jane@example.com', 'SEC REGISTRATION DATE' => '7/23/2024 00:00:00'],
                        ['Name' => 'John', 'Email' => 'john@example.com', 'SEC REGISTRATION DATE' => '7/23/2025 00:00:00'],
                    ],
                ]);
        });

        $response = $this->post(route('document-generator.batches.store'), [
            'excel_file' => UploadedFile::fake()->create('source.xlsx', 20),
            'default_template_file' => UploadedFile::fake()->create('template.docx', 20),
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertCreated()->assertJsonStructure(['batch_id', 'status', 'total_items']);

        $this->assertDatabaseHas('document_batches', [
            'user_id' => $user->id,
            'total_items' => 2,
            'status' => 'queued',
        ]);

        $this->assertDatabaseCount('document_batch_items', 2);
        $this->assertDatabaseCount('document_batch_templates', 1);
        Queue::assertPushed(GenerateDocumentBatchItemJob::class, 2);
    }

    public function test_batch_creation_uses_previous_batch_workbook_to_backfill_matching_company_rows(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $previousBatch = DocumentBatch::factory()->for($user)->create([
            'excel_path' => "document-generator/{$user->id}/uploads/previous.xlsx",
        ]);
        Storage::disk('local')->put((string) $previousBatch->excel_path, 'previous-workbook');

        $this->mock(ExcelExtractionService::class, function ($mock): void {
            $mock
                ->shouldReceive('extract')
                ->once()
                ->with(Mockery::type('string'), 0)
                ->ordered()
                ->andReturn([
                    'headers' => ['Company Name', 'NET INCOME 2025', 'SEC REGISTRATION DATE'],
                    'rows' => [[
                        'Company Name' => 'Acme Corp',
                        'NET INCOME 2025' => '30',
                        'SEC REGISTRATION DATE' => '7/23/2025 00:00:00',
                    ]],
                ]);

            $mock
                ->shouldReceive('extract')
                ->once()
                ->with(Mockery::type('string'), 0)
                ->ordered()
                ->andReturn([
                    'headers' => ['Company Name', 'NET INCOME'],
                    'rows' => [[
                        'Company Name' => ' Acme Corp ',
                        'NET INCOME' => '20',
                    ]],
                ]);

            $mock
                ->shouldReceive('extract')
                ->once()
                ->with(Mockery::type('string'), 0)
                ->ordered()
                ->andReturn([
                    'headers' => ['Company Name', 'NET INCOME'],
                    'rows' => [[
                        'Company Name' => 'Acme Corp',
                        'NET INCOME' => '20',
                    ]],
                ]);
        });

        $this->post(route('document-generator.batches.store'), [
            'excel_file' => UploadedFile::fake()->create('source.xlsx', 20),
            'default_template_file' => UploadedFile::fake()->create('template.docx', 20),
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertCreated();

        $batch = DocumentBatch::query()->latest('id')->firstOrFail();
        $item = DocumentBatchItem::query()->where('document_batch_id', $batch->id)->firstOrFail();

        $this->assertSame('20', $item->row_data['NET INCOME'] ?? null);
        $this->assertSame('30', $item->row_data['NET INCOME 2025'] ?? null);
        $this->assertContains('NET INCOME', $batch->headers_json ?? []);
    }

    public function test_batch_creation_does_not_merge_previous_workbook_data_when_company_name_does_not_match(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $previousBatch = DocumentBatch::factory()->for($user)->create([
            'excel_path' => "document-generator/{$user->id}/uploads/previous.xlsx",
        ]);
        Storage::disk('local')->put((string) $previousBatch->excel_path, 'previous-workbook');

        $this->mock(ExcelExtractionService::class, function ($mock): void {
            $mock
                ->shouldReceive('extract')
                ->once()
                ->with(Mockery::type('string'), 0)
                ->ordered()
                ->andReturn([
                    'headers' => ['Company Name', 'NET INCOME 2025', 'SEC REGISTRATION DATE'],
                    'rows' => [[
                        'Company Name' => 'Acme Corp',
                        'NET INCOME 2025' => '30',
                        'SEC REGISTRATION DATE' => '7/23/2025 00:00:00',
                    ]],
                ]);

            $mock
                ->shouldReceive('extract')
                ->once()
                ->with(Mockery::type('string'), 0)
                ->ordered()
                ->andReturn([
                    'headers' => ['Company Name', 'NET INCOME'],
                    'rows' => [[
                        'Company Name' => 'Different Corp',
                        'NET INCOME' => '20',
                    ]],
                ]);
        });

        $this->post(route('document-generator.batches.store'), [
            'excel_file' => UploadedFile::fake()->create('source.xlsx', 20),
            'default_template_file' => UploadedFile::fake()->create('template.docx', 20),
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertCreated();

        $batch = DocumentBatch::query()->latest('id')->firstOrFail();
        $item = DocumentBatchItem::query()->where('document_batch_id', $batch->id)->firstOrFail();

        $this->assertSame('30', $item->row_data['NET INCOME 2025'] ?? null);
        $this->assertArrayNotHasKey('NET INCOME', $item->row_data);
        $this->assertNotContains('NET INCOME', $batch->headers_json ?? []);
    }

    public function test_batch_creation_prefers_current_workbook_values_over_previous_batch_values(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $previousBatch = DocumentBatch::factory()->for($user)->create([
            'excel_path' => "document-generator/{$user->id}/uploads/previous.xlsx",
        ]);
        Storage::disk('local')->put((string) $previousBatch->excel_path, 'previous-workbook');

        $this->mock(ExcelExtractionService::class, function ($mock): void {
            $mock
                ->shouldReceive('extract')
                ->once()
                ->with(Mockery::type('string'), 0)
                ->ordered()
                ->andReturn([
                    'headers' => ['Company Name', 'NET INCOME', 'NET INCOME 2025', 'SEC REGISTRATION DATE'],
                    'rows' => [[
                        'Company Name' => 'Acme Corp',
                        'NET INCOME' => '25',
                        'NET INCOME 2025' => '30',
                        'SEC REGISTRATION DATE' => '7/23/2025 00:00:00',
                    ]],
                ]);

            $mock
                ->shouldReceive('extract')
                ->once()
                ->with(Mockery::type('string'), 0)
                ->ordered()
                ->andReturn([
                    'headers' => ['Company Name', 'NET INCOME'],
                    'rows' => [[
                        'Company Name' => 'Acme Corp',
                        'NET INCOME' => '20',
                    ]],
                ]);
        });

        $this->post(route('document-generator.batches.store'), [
            'excel_file' => UploadedFile::fake()->create('source.xlsx', 20),
            'default_template_file' => UploadedFile::fake()->create('template.docx', 20),
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertCreated();

        $item = DocumentBatchItem::query()->latest('id')->firstOrFail();

        $this->assertSame('25', $item->row_data['NET INCOME'] ?? null);
    }

    public function test_batch_creation_stores_year_threshold_templates(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->mock(ExcelExtractionService::class, function ($mock): void {
            $mock
                ->shouldReceive('extract')
                ->once()
                ->with(Mockery::type('string'), 0)
                ->andReturn([
                    'headers' => ['SEC REGISTRATION DATE'],
                    'rows' => [
                        ['SEC REGISTRATION DATE' => '7/23/2024 00:00:00'],
                    ],
                ]);
        });

        $response = $this->post(route('document-generator.batches.store'), [
            'excel_file' => UploadedFile::fake()->create('source.xlsx', 20),
            'default_template_file' => UploadedFile::fake()->create('default.docx', 20),
            'year_templates' => [
                [
                    'year' => 2025,
                    'template_file' => UploadedFile::fake()->create('2025.docx', 20),
                ],
                [
                    'year' => 2027,
                    'template_file' => UploadedFile::fake()->create('2027.docx', 20),
                ],
            ],
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $response->assertCreated();
        $this->assertDatabaseCount('document_batch_templates', 3);
        $this->assertDatabaseHas('document_batch_templates', [
            'year' => null,
            'template_name' => 'default.docx',
        ]);
        $this->assertDatabaseHas('document_batch_templates', [
            'year' => 2025,
            'template_name' => '2025.docx',
        ]);
        $this->assertDatabaseHas('document_batch_templates', [
            'year' => 2027,
            'template_name' => '2027.docx',
        ]);
    }

    public function test_batch_creation_rejects_duplicate_year_thresholds(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post(route('document-generator.batches.store'), [
            'excel_file' => UploadedFile::fake()->create('source.xlsx', 20),
            'default_template_file' => UploadedFile::fake()->create('default.docx', 20),
            'year_templates' => [
                [
                    'year' => 2025,
                    'template_file' => UploadedFile::fake()->create('first.docx', 20),
                ],
                [
                    'year' => 2025,
                    'template_file' => UploadedFile::fake()->create('second.docx', 20),
                ],
            ],
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['year_templates.1.year']);
    }

    public function test_batch_creation_rejects_incomplete_year_threshold_entries(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post(route('document-generator.batches.store'), [
            'excel_file' => UploadedFile::fake()->create('source.xlsx', 20),
            'default_template_file' => UploadedFile::fake()->create('default.docx', 20),
            'year_templates' => [
                [
                    'year' => 2025,
                ],
            ],
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['year_templates.0.template_file']);
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

    public function test_authenticated_user_can_view_generated_files_batch_folder_page(): void
    {
        $user = User::factory()->create();
        $batch = DocumentBatch::factory()->for($user)->create([
            'status' => 'completed',
            'success_items' => 1,
        ]);

        $this->actingAs($user);

        $this->get(route('generated-files.index'))
            ->assertOk()
            ->assertSee('GeneratedFiles')
            ->assertSee($batch->source_excel_name);
    }

    public function test_authenticated_user_can_view_generated_files_batch_items_page(): void
    {
        $user = User::factory()->create();
        $batch = DocumentBatch::factory()->for($user)->create([
            'status' => 'completed',
            'total_items' => 1,
            'success_items' => 1,
            'processed_items' => 1,
        ]);
        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'pdf_done',
            'docx_path' => "document-generator/{$user->id}/batch-{$batch->id}/row-2.docx",
            'pdf_path' => "document-generator/{$user->id}/batch-{$batch->id}/row-2.pdf",
        ]);

        Storage::fake('local');
        Storage::disk('local')->put($item->docx_path, 'docx-content');
        Storage::disk('local')->put($item->pdf_path, 'pdf-content');

        $this->actingAs($user);

        $this->get(route('generated-files.show', $batch))
            ->assertOk()
            ->assertSee('GeneratedBatchItems')
            ->assertSee($batch->source_excel_name)
            ->assertSee((string) $batch->id);
    }

    public function test_authenticated_owner_can_view_batch_template_mapping_page(): void
    {
        $user = User::factory()->create();
        $batch = $this->createBatchWithTemplate($user);

        DocumentBatchTemplate::factory()->create([
            'document_batch_id' => $batch->id,
            'year' => 2025,
            'template_name' => 'template-2025.docx',
            'template_path' => 'document-generator/template-2025.docx',
        ]);

        $this->actingAs($user)
            ->get(route('document-generator.batches.template-mapping', $batch))
            ->assertOk()
            ->assertSee('BatchTemplateMapping')
            ->assertSee('template.docx')
            ->assertSee('template-2025.docx');
    }

    public function test_batch_items_page_keeps_generated_files_navigation(): void
    {
        $user = User::factory()->create();
        $batch = $this->createBatchWithTemplate($user);

        $this->actingAs($user)
            ->get(route('generated-files.show', $batch))
            ->assertOk()
            ->assertSee('Back to Batch Folders');
    }

    public function test_document_generator_exposes_global_template_mapping_link(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('document-generator.index'))
            ->assertOk()
            ->assertSee('/document-generator/template-mapping')
            ->assertSee('Template Mapping');
    }

    public function test_document_generator_global_template_mapping_page_loads(): void
    {
        $user = User::factory()->create();
        DocumentGeneratorTemplate::factory()->create([
            'year' => null,
            'template_name' => 'global-default.docx',
            'template_path' => 'document-generator/global-templates/global-default.docx',
        ]);
        DocumentGeneratorTemplate::factory()->create([
            'year' => 2025,
            'template_name' => 'global-2025.docx',
            'template_path' => 'document-generator/global-templates/global-2025.docx',
        ]);

        $this->actingAs($user)
            ->get(route('document-generator.template-mapping'))
            ->assertOk()
            ->assertSee('TemplateMapping')
            ->assertSee('global-default.docx')
            ->assertSee('global-2025.docx');
    }

    public function test_owner_can_update_global_default_template_mapping(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        DocumentGeneratorTemplate::factory()->create([
            'year' => null,
            'template_name' => 'global-default.docx',
            'template_path' => 'document-generator/global-templates/global-default.docx',
        ]);
        Storage::disk('local')->put('document-generator/global-templates/global-default.docx', 'old-default');

        $this->actingAs($user)
            ->post(route('document-generator.templates.default'), [
                'template_file' => UploadedFile::fake()->create('new-global-default.docx', 20),
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertOk()
            ->assertJsonPath('default_template.template_name', 'new-global-default.docx');

        $this->assertDatabaseHas('document_generator_templates', [
            'year' => null,
            'template_name' => 'new-global-default.docx',
        ]);
        Storage::disk('local')->assertMissing('document-generator/global-templates/global-default.docx');
    }

    public function test_batch_creation_can_use_global_template_mapping_without_uploaded_default(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        DocumentGeneratorTemplate::factory()->create([
            'year' => null,
            'template_name' => 'global-default.docx',
            'template_path' => 'document-generator/global-templates/global-default.docx',
        ]);
        DocumentGeneratorTemplate::factory()->create([
            'year' => 2025,
            'template_name' => 'global-2025.docx',
            'template_path' => 'document-generator/global-templates/global-2025.docx',
        ]);
        Storage::disk('local')->put('document-generator/global-templates/global-default.docx', 'global-default');
        Storage::disk('local')->put('document-generator/global-templates/global-2025.docx', 'global-2025');

        $this->mock(ExcelExtractionService::class, function ($mock): void {
            $mock
                ->shouldReceive('extract')
                ->once()
                ->with(Mockery::type('string'), 0)
                ->andReturn([
                    'headers' => ['SEC REGISTRATION DATE'],
                    'rows' => [
                        ['SEC REGISTRATION DATE' => '7/23/2026 00:00:00'],
                    ],
                ]);
        });

        $this->post(route('document-generator.batches.store'), [
            'excel_file' => UploadedFile::fake()->create('source.xlsx', 20),
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
            ->assertCreated()
            ->assertJsonPath('total_items', 1);

        $batch = DocumentBatch::query()->latest('id')->firstOrFail();

        $this->assertSame('global-default.docx', $batch->template_name);
        $this->assertDatabaseHas('document_batch_templates', [
            'document_batch_id' => $batch->id,
            'year' => null,
            'template_name' => 'global-default.docx',
        ]);
        $this->assertDatabaseHas('document_batch_templates', [
            'document_batch_id' => $batch->id,
            'year' => 2025,
            'template_name' => 'global-2025.docx',
        ]);
        Storage::disk('local')->assertExists($batch->template_path);
    }

    public function test_owner_can_replace_default_template_for_a_batch_only(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $batch = $this->createBatchWithTemplate($owner);
        $otherBatch = $this->createBatchWithTemplate($owner, [
            'template_name' => 'other-template.docx',
            'template_path' => 'document-generator/other-template.docx',
        ]);

        $otherDefaultTemplate = DocumentBatchTemplate::query()
            ->where('document_batch_id', $otherBatch->id)
            ->whereNull('year')
            ->firstOrFail();
        $otherDefaultTemplate->forceFill([
            'template_name' => 'other-template.docx',
            'template_path' => 'document-generator/other-template.docx',
        ])->save();
        Storage::disk('local')->put('document-generator/other-template.docx', 'other-template-content');

        $this->actingAs($owner)
            ->post(route('document-generator.batches.templates.default', $batch), [
                'template_file' => UploadedFile::fake()->create('replacement.docx', 20),
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertOk()
            ->assertJsonPath('default_template.template_name', 'replacement.docx');

        $batch->refresh();
        $defaultTemplate = DocumentBatchTemplate::query()
            ->where('document_batch_id', $batch->id)
            ->whereNull('year')
            ->firstOrFail();
        $otherBatch->refresh();
        $otherDefaultTemplate->refresh();

        $this->assertSame('replacement.docx', $batch->template_name);
        $this->assertSame('replacement.docx', $defaultTemplate->template_name);
        $this->assertSame('other-template.docx', $otherBatch->template_name);
        $this->assertSame('other-template.docx', $otherDefaultTemplate->template_name);
        Storage::disk('local')->assertMissing('document-generator/template.docx');
        Storage::disk('local')->assertExists($batch->template_path);
    }

    public function test_owner_can_add_year_template_mapping(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $batch = $this->createBatchWithTemplate($owner);

        $this->actingAs($owner)
            ->post(route('document-generator.batches.templates.store', $batch), [
                'year' => 2026,
                'template_file' => UploadedFile::fake()->create('template-2026.docx', 20),
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertCreated()
            ->assertJsonPath('year_templates.0.template_name', 'template-2026.docx');

        $this->assertDatabaseHas('document_batch_templates', [
            'document_batch_id' => $batch->id,
            'year' => 2026,
            'template_name' => 'template-2026.docx',
        ]);
    }

    public function test_owner_can_update_year_template_year_and_file(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $batch = $this->createBatchWithTemplate($owner);
        $template = DocumentBatchTemplate::factory()->create([
            'document_batch_id' => $batch->id,
            'year' => 2025,
            'template_name' => 'template-2025.docx',
            'template_path' => 'document-generator/template-2025.docx',
        ]);
        Storage::disk('local')->put('document-generator/template-2025.docx', 'template-2025-content');

        $this->actingAs($owner)
            ->post(route('document-generator.batches.templates.update', [$batch, $template]), [
                'year' => 2028,
                'template_file' => UploadedFile::fake()->create('template-2028.docx', 20),
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertOk()
            ->assertJsonPath('year_templates.0.year', 2028)
            ->assertJsonPath('year_templates.0.template_name', 'template-2028.docx');

        $template->refresh();

        $this->assertSame(2028, $template->year);
        $this->assertSame('template-2028.docx', $template->template_name);
        Storage::disk('local')->assertMissing('document-generator/template-2025.docx');
        Storage::disk('local')->assertExists($template->template_path);
    }

    public function test_owner_cannot_save_duplicate_year_template_mapping(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $batch = $this->createBatchWithTemplate($owner);
        $template = DocumentBatchTemplate::factory()->create([
            'document_batch_id' => $batch->id,
            'year' => 2025,
            'template_name' => 'template-2025.docx',
            'template_path' => 'document-generator/template-2025.docx',
        ]);
        DocumentBatchTemplate::factory()->create([
            'document_batch_id' => $batch->id,
            'year' => 2027,
            'template_name' => 'template-2027.docx',
            'template_path' => 'document-generator/template-2027.docx',
        ]);

        $this->actingAs($owner)
            ->post(route('document-generator.batches.templates.update', [$batch, $template]), [
                'year' => 2027,
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['year']);
    }

    public function test_owner_can_remove_year_template_mapping(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $batch = $this->createBatchWithTemplate($owner);
        $template = DocumentBatchTemplate::factory()->create([
            'document_batch_id' => $batch->id,
            'year' => 2025,
            'template_name' => 'template-2025.docx',
            'template_path' => 'document-generator/template-2025.docx',
        ]);
        Storage::disk('local')->put('document-generator/template-2025.docx', 'template-2025-content');

        $this->actingAs($owner)
            ->delete(route('document-generator.batches.templates.destroy', [$batch, $template]), [], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertOk()
            ->assertJsonCount(0, 'year_templates');

        $this->assertDatabaseMissing('document_batch_templates', [
            'id' => $template->id,
        ]);
        Storage::disk('local')->assertMissing('document-generator/template-2025.docx');
    }

    public function test_company_search_filters_batch_items_without_breaking_items_endpoint(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $batch = $this->createBatchWithTemplate($user);
        $matchingItem = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_data' => ['Company Name' => 'Acme Holdings'],
        ]);
        DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_data' => ['Company Name' => 'Beta Corp'],
        ]);

        $this->getJson(route('document-generator.batches.items', [
            'batch' => $batch,
            'company_search' => 'acme',
        ]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $matchingItem->id)
            ->assertJsonCount(1, 'data');
    }

    public function test_job_allows_generation_when_placeholder_has_no_matching_header(): void
    {
        Storage::fake('local');

        $batch = $this->createBatchWithTemplate();
        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_number' => 2,
            'row_data' => [
                'Name' => 'Jane',
                'SEC REGISTRATION DATE' => '7/23/2024 00:00:00',
            ],
            'status' => 'queued',
        ]);

        $docxService = Mockery::mock(DocxTemplateService::class);
        $docxService->shouldReceive('validateRowData')
            ->once()
            ->andReturn([
                'missing_data' => [],
                'errors' => [],
            ]);
        $docxService->shouldReceive('render')
            ->once()
            ->andReturnUsing(function (string $templatePath, string $outputPath, array $rowData = [], ?int $selectedTemplateYear = null): void {
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
            'row_data' => ['inn' => '', 'SEC REGISTRATION DATE' => '7/23/2024 00:00:00'],
            'status' => 'queued',
        ]);

        $docxService = Mockery::mock(DocxTemplateService::class);
        $docxService->shouldReceive('validateRowData')
            ->once()
            ->andReturn([
                'missing_data' => ['inn'],
                'errors' => [],
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
        $this->assertSame([
            'missing_data' => ['inn'],
            'errors' => [],
        ], $item->error_details);
        $this->assertDatabaseHas('document_batch_item_activity_logs', [
            'document_batch_item_id' => $item->id,
            'action' => 'generation_failed_validation',
        ]);
    }

    public function test_job_uses_2025_template_auto_sum_placeholder(): void
    {
        Storage::fake('local');

        $batch = $this->createBatchWithDocxTemplate([
            'NET INCOME',
        ]);
        $this->addYearDocxTemplateToBatch($batch, 2025, [
            'NET INCOME',
        ], 'template-2025.docx');

        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_number' => 2,
            'row_data' => [
                'NET INCOME' => '20',
                'NET INCOME 2025' => '30',
                'SEC REGISTRATION DATE' => '7/23/2025 00:00:00',
            ],
            'status' => 'queued',
        ]);

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
            app(DocxTemplateService::class),
            $pdfService,
        );

        $item->refresh();

        $this->assertSame('pdf_done', $item->status);
        $this->assertNotNull($item->docx_path);
        $this->assertStringContainsString(
            '50.00',
            $this->readDocxDocumentXml(Storage::disk('local')->path((string) $item->docx_path))
        );
    }

    public function test_job_keeps_direct_2025_placeholder_in_non_2025_template(): void
    {
        Storage::fake('local');

        $batch = $this->createBatchWithDocxTemplate([
            'NET INCOME 2025',
        ]);
        $this->addYearDocxTemplateToBatch($batch, 2025, [
            'NET INCOME 2025',
        ], 'template-2025.docx');

        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_number' => 2,
            'row_data' => [
                'NET INCOME' => '20',
                'NET INCOME 2025' => '30',
                'SEC REGISTRATION DATE' => '7/23/2024 00:00:00',
            ],
            'status' => 'queued',
        ]);

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
            app(DocxTemplateService::class),
            $pdfService,
        );

        $item->refresh();

        $this->assertSame('pdf_done', $item->status);
        $this->assertNotNull($item->docx_path);
        $this->assertStringContainsString(
            '30.00',
            $this->readDocxDocumentXml(Storage::disk('local')->path((string) $item->docx_path))
        );
    }

    public function test_job_marks_item_failed_when_2025_auto_sum_placeholder_requires_missing_header(): void
    {
        Storage::fake('local');

        $batch = $this->createBatchWithDocxTemplate([
            'NET INCOME',
        ]);
        $this->addYearDocxTemplateToBatch($batch, 2025, [
            'NET INCOME',
        ], 'template-2025.docx');

        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_number' => 2,
            'row_data' => [
                'NET INCOME 2025' => '30',
                'SEC REGISTRATION DATE' => '7/23/2025 00:00:00',
            ],
            'status' => 'queued',
        ]);

        $pdfService = Mockery::mock(PdfConversionService::class);
        $pdfService->shouldNotReceive('convertDocxToPdf');

        (new GenerateDocumentBatchItemJob($item->id))->handle(
            app(\App\Services\DocumentBatchActivityLogger::class),
            app(DocxTemplateService::class),
            $pdfService,
        );

        $item->refresh();

        $this->assertSame('failed', $item->status);
        $this->assertStringContainsString('{NET INCOME}', (string) $item->error_message);
        $this->assertStringContainsString('NET INCOME', (string) $item->error_message);
        $this->assertDatabaseHas('document_batch_item_activity_logs', [
            'document_batch_item_id' => $item->id,
            'action' => 'generation_failed_validation',
        ]);
    }

    public function test_job_can_use_previous_batch_backfill_for_2025_auto_sum_placeholder(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $previousBatch = DocumentBatch::factory()->for($user)->create([
            'excel_path' => "document-generator/{$user->id}/uploads/previous.xlsx",
        ]);
        Storage::disk('local')->put((string) $previousBatch->excel_path, 'previous-workbook');

        $batch = $this->createBatchWithDocxTemplate([
            'NET INCOME',
        ], $user);
        $this->addYearDocxTemplateToBatch($batch, 2025, [
            'NET INCOME',
        ], 'template-2025.docx');

        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_number' => 2,
            'row_data' => [
                'Company Name' => 'Acme Corp',
                'NET INCOME 2025' => '30',
                'SEC REGISTRATION DATE' => '7/23/2025 00:00:00',
            ],
            'status' => 'queued',
        ]);

        $this->mock(ExcelExtractionService::class, function ($mock): void {
            $mock
                ->shouldReceive('extract')
                ->once()
                ->with(Mockery::type('string'), 0)
                ->andReturn([
                    'headers' => ['Company Name', 'NET INCOME'],
                    'rows' => [[
                        'Company Name' => 'Acme Corp',
                        'NET INCOME' => '20',
                    ]],
                ]);
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
            app(DocxTemplateService::class),
            $pdfService,
        );

        $item->refresh();

        $this->assertSame('pdf_done', $item->status);
        $this->assertNotNull($item->docx_path);
        $this->assertStringContainsString(
            '50.00',
            $this->readDocxDocumentXml(Storage::disk('local')->path((string) $item->docx_path))
        );
    }

    public function test_job_fails_for_2025_auto_sum_placeholder_when_no_previous_batch_data_exists(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->mock(ExcelExtractionService::class, function ($mock): void {
            $mock
                ->shouldReceive('extract')
                ->once()
                ->with(Mockery::type('string'), 0)
                ->andReturn([
                    'headers' => ['Company Name', 'NET INCOME 2025', 'SEC REGISTRATION DATE'],
                    'rows' => [[
                        'Company Name' => 'Acme Corp',
                        'NET INCOME 2025' => '30',
                        'SEC REGISTRATION DATE' => '7/23/2025 00:00:00',
                    ]],
                ]);
        });

        $this->post(route('document-generator.batches.store'), [
            'excel_file' => UploadedFile::fake()->create('source.xlsx', 20),
            'default_template_file' => $this->createDocxUploadedFile('default.docx', ['NET INCOME']),
            'year_templates' => [
                [
                    'year' => 2025,
                    'template_file' => $this->createDocxUploadedFile('2025.docx', ['NET INCOME']),
                ],
            ],
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertCreated();

        $item = DocumentBatchItem::query()->latest('id')->firstOrFail();

        $pdfService = Mockery::mock(PdfConversionService::class);
        $pdfService->shouldNotReceive('convertDocxToPdf');

        (new GenerateDocumentBatchItemJob($item->id))->handle(
            app(\App\Services\DocumentBatchActivityLogger::class),
            app(DocxTemplateService::class),
            $pdfService,
        );

        $item->refresh();

        $this->assertSame('failed', $item->status);
        $this->assertStringContainsString('{NET INCOME}', (string) $item->error_message);
        $this->assertStringContainsString('NET INCOME', (string) $item->error_message);
    }

    public function test_job_can_use_previous_batch_backfill_for_2025_subtraction_placeholder(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $this->actingAs($user);

        $previousBatch = DocumentBatch::factory()->for($user)->create([
            'excel_path' => "document-generator/{$user->id}/uploads/previous.xlsx",
        ]);
        Storage::disk('local')->put((string) $previousBatch->excel_path, 'previous-workbook');

        $this->mock(ExcelExtractionService::class, function ($mock): void {
            $mock
                ->shouldReceive('extract')
                ->once()
                ->with(Mockery::type('string'), 0)
                ->ordered()
                ->andReturn([
                    'headers' => ['Company Name', 'TRADE RECEIVABLES 2025', 'SEC REGISTRATION DATE'],
                    'rows' => [[
                        'Company Name' => 'Acme Corp',
                        'TRADE RECEIVABLES 2025' => '120',
                        'SEC REGISTRATION DATE' => '7/23/2025 00:00:00',
                    ]],
                ]);

            $mock
                ->shouldReceive('extract')
                ->once()
                ->with(Mockery::type('string'), 0)
                ->ordered()
                ->andReturn([
                    'headers' => ['Company Name', 'TRADE RECEIVABLES'],
                    'rows' => [[
                        'Company Name' => 'Acme Corp',
                        'TRADE RECEIVABLES' => '20',
                    ]],
                ]);

            $mock
                ->shouldReceive('extract')
                ->once()
                ->with(Mockery::type('string'), 0)
                ->ordered()
                ->andReturn([
                    'headers' => ['Company Name', 'TRADE RECEIVABLES'],
                    'rows' => [[
                        'Company Name' => 'Acme Corp',
                        'TRADE RECEIVABLES' => '20',
                    ]],
                ]);
        });

        $this->post(route('document-generator.batches.store'), [
            'excel_file' => UploadedFile::fake()->create('source.xlsx', 20),
            'default_template_file' => $this->createDocxUploadedFile('default.docx', ['TRADE RECEIVABLES']),
            'year_templates' => [
                [
                    'year' => 2025,
                    'template_file' => $this->createDocxUploadedFile('2025.docx', ['TRADE RECEIVABLES 2025-TRADE RECEIVABLES']),
                ],
            ],
        ], [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertCreated();

        $item = DocumentBatchItem::query()->latest('id')->firstOrFail();
        $this->assertSame('20', $item->row_data['TRADE RECEIVABLES'] ?? null);

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
            app(DocxTemplateService::class),
            $pdfService,
        );

        $item->refresh();

        $this->assertSame('pdf_done', $item->status);
        $this->assertNotNull($item->docx_path);
        $this->assertStringContainsString(
            '100.00',
            $this->readDocxDocumentXml(Storage::disk('local')->path((string) $item->docx_path))
        );
    }

    public function test_job_treats_plain_current_header_as_2025_value_for_auto_sum_placeholder(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $previousBatch = DocumentBatch::factory()->for($user)->create([
            'excel_path' => "document-generator/{$user->id}/uploads/previous.xlsx",
        ]);
        Storage::disk('local')->put((string) $previousBatch->excel_path, 'previous-workbook');

        $batch = $this->createBatchWithDocxTemplate([
            'NET INCOME',
        ], $user);
        $this->addYearDocxTemplateToBatch($batch, 2025, [
            'NET INCOME',
        ], 'template-2025.docx');

        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_number' => 2,
            'row_data' => [
                'Company Name' => 'Acme Corp',
                'NET INCOME' => '30',
                'SEC REGISTRATION DATE' => '7/23/2025 00:00:00',
            ],
            'status' => 'queued',
        ]);

        $this->mock(ExcelExtractionService::class, function ($mock): void {
            $mock
                ->shouldReceive('extract')
                ->once()
                ->with(Mockery::type('string'), 0)
                ->andReturn([
                    'headers' => ['Company Name', 'NET INCOME'],
                    'rows' => [[
                        'Company Name' => 'Acme Corp',
                        'NET INCOME' => '20',
                    ]],
                ]);
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
            app(DocxTemplateService::class),
            $pdfService,
        );

        $item->refresh();

        $this->assertSame('pdf_done', $item->status);
        $this->assertNotNull($item->docx_path);
        $this->assertStringContainsString(
            '50.00',
            $this->readDocxDocumentXml(Storage::disk('local')->path((string) $item->docx_path))
        );
    }

    public function test_job_treats_plain_current_header_as_2025_value_for_subtraction_placeholder(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $previousBatch = DocumentBatch::factory()->for($user)->create([
            'excel_path' => "document-generator/{$user->id}/uploads/previous.xlsx",
        ]);
        Storage::disk('local')->put((string) $previousBatch->excel_path, 'previous-workbook');

        $batch = $this->createBatchWithDocxTemplate([
            'TRADE RECEIVABLES',
        ], $user);
        $this->addYearDocxTemplateToBatch($batch, 2025, [
            'TRADE RECEIVABLES 2025-TRADE RECEIVABLES',
        ], 'template-2025.docx');

        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_number' => 2,
            'row_data' => [
                'Company Name' => 'Acme Corp',
                'TRADE RECEIVABLES' => '120',
                'SEC REGISTRATION DATE' => '7/23/2025 00:00:00',
            ],
            'status' => 'queued',
        ]);

        $this->mock(ExcelExtractionService::class, function ($mock): void {
            $mock
                ->shouldReceive('extract')
                ->once()
                ->with(Mockery::type('string'), 0)
                ->andReturn([
                    'headers' => ['Company Name', 'TRADE RECEIVABLES'],
                    'rows' => [[
                        'Company Name' => 'Acme Corp',
                        'TRADE RECEIVABLES' => '20',
                    ]],
                ]);
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
            app(DocxTemplateService::class),
            $pdfService,
        );

        $item->refresh();

        $this->assertSame('pdf_done', $item->status);
        $this->assertNotNull($item->docx_path);
        $this->assertStringContainsString(
            '100.00',
            $this->readDocxDocumentXml(Storage::disk('local')->path((string) $item->docx_path))
        );
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
            'row_data' => ['inn' => '', 'SEC REGISTRATION DATE' => '7/23/2024 00:00:00'],
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
                'errors' => [],
            ]);
        $docxService->shouldReceive('render')
            ->once()
            ->andReturnUsing(function (string $templatePath, string $outputPath, array $rowData = [], ?int $selectedTemplateYear = null): void {
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
            'row_data' => ['inn' => '111', 'SEC REGISTRATION DATE' => '7/23/2024 00:00:00'],
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

    public function test_job_uses_default_template_for_years_below_first_threshold(): void
    {
        Storage::fake('local');

        $batch = $this->createBatchWithTemplate();
        DocumentBatchTemplate::factory()->create([
            'document_batch_id' => $batch->id,
            'year' => 2025,
            'template_name' => 'template-2025.docx',
            'template_path' => 'document-generator/template-2025.docx',
        ]);
        Storage::disk('local')->put('document-generator/template-2025.docx', 'template-2025-content');

        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_number' => 2,
            'row_data' => ['SEC REGISTRATION DATE' => '7/23/2024 00:00:00'],
            'status' => 'queued',
        ]);

        $docxService = Mockery::mock(DocxTemplateService::class);
        $docxService->shouldReceive('validateRowData')
            ->once()
            ->withArgs(function (string $templatePath, ...$args): bool {
                return str_ends_with($templatePath, 'document-generator/template.docx');
            })
            ->andReturn(['missing_data' => [], 'errors' => []]);
        $docxService->shouldReceive('render')
            ->once()
            ->withArgs(function (string $templatePath, string $outputPath, array $rowData, ?int $selectedTemplateYear = null): bool {
                return str_ends_with($templatePath, 'document-generator/template.docx');
            })
            ->andReturnUsing(function (string $templatePath, string $outputPath, array $rowData = [], ?int $selectedTemplateYear = null): void {
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
    }

    public function test_job_uses_threshold_template_for_exact_and_higher_years(): void
    {
        Storage::fake('local');

        $batch = $this->createBatchWithTemplate();
        DocumentBatchTemplate::factory()->create([
            'document_batch_id' => $batch->id,
            'year' => 2025,
            'template_name' => 'template-2025.docx',
            'template_path' => 'document-generator/template-2025.docx',
        ]);
        Storage::disk('local')->put('document-generator/template-2025.docx', 'template-2025-content');

        foreach (['7/23/2025 00:00:00', '7/23/2026 00:00:00'] as $dateValue) {
            $item = DocumentBatchItem::factory()->create([
                'document_batch_id' => $batch->id,
                'row_number' => fake()->numberBetween(2, 999),
                'row_data' => ['SEC REGISTRATION DATE' => $dateValue],
                'status' => 'queued',
            ]);

            $docxService = Mockery::mock(DocxTemplateService::class);
            $docxService->shouldReceive('placeholderKeys')
                ->once()
                ->with(Mockery::on(static fn (string $templatePath): bool => str_ends_with($templatePath, 'document-generator/template-2025.docx')))
                ->andReturn([]);
            $docxService->shouldReceive('validateRowData')
                ->once()
                ->withArgs(function (string $templatePath, ...$args): bool {
                    return str_ends_with($templatePath, 'document-generator/template-2025.docx');
                })
                ->andReturn(['missing_data' => [], 'errors' => []]);
            $docxService->shouldReceive('render')
                ->once()
                ->withArgs(function (string $templatePath, string $outputPath, array $rowData, ?int $selectedTemplateYear = null): bool {
                    return str_ends_with($templatePath, 'document-generator/template-2025.docx');
                })
                ->andReturnUsing(function (string $templatePath, string $outputPath, array $rowData = [], ?int $selectedTemplateYear = null): void {
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
        }
    }

    public function test_job_uses_2025_threshold_template_for_single_digit_excel_datetime_format(): void
    {
        Storage::fake('local');

        $batch = $this->createBatchWithTemplate();
        DocumentBatchTemplate::factory()->create([
            'document_batch_id' => $batch->id,
            'year' => 2025,
            'template_name' => 'template-2025.docx',
            'template_path' => 'document-generator/template-2025.docx',
        ]);
        Storage::disk('local')->put('document-generator/template-2025.docx', 'template-2025-content');

        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_number' => 2,
            'row_data' => ['SEC REGISTRATION DATE' => '10/3/2025 0:00'],
            'status' => 'queued',
        ]);

        $docxService = Mockery::mock(DocxTemplateService::class);
        $docxService->shouldReceive('placeholderKeys')
            ->once()
            ->with(Mockery::on(static fn (string $templatePath): bool => str_ends_with($templatePath, 'document-generator/template-2025.docx')))
            ->andReturn([]);
        $docxService->shouldReceive('validateRowData')
            ->once()
            ->withArgs(function (string $templatePath, ...$args): bool {
                return str_ends_with($templatePath, 'document-generator/template-2025.docx');
            })
            ->andReturn(['missing_data' => [], 'errors' => []]);
        $docxService->shouldReceive('render')
            ->once()
            ->withArgs(function (string $templatePath, string $outputPath, array $rowData, ?int $selectedTemplateYear = null): bool {
                return str_ends_with($templatePath, 'document-generator/template-2025.docx');
            })
            ->andReturnUsing(function (string $templatePath, string $outputPath, array $rowData = [], ?int $selectedTemplateYear = null): void {
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
    }

    public function test_job_uses_nearest_lower_threshold_when_between_configured_years(): void
    {
        Storage::fake('local');

        $batch = $this->createBatchWithTemplate();
        DocumentBatchTemplate::factory()->create([
            'document_batch_id' => $batch->id,
            'year' => 2025,
            'template_name' => 'template-2025.docx',
            'template_path' => 'document-generator/template-2025.docx',
        ]);
        DocumentBatchTemplate::factory()->create([
            'document_batch_id' => $batch->id,
            'year' => 2027,
            'template_name' => 'template-2027.docx',
            'template_path' => 'document-generator/template-2027.docx',
        ]);
        Storage::disk('local')->put('document-generator/template-2025.docx', 'template-2025-content');
        Storage::disk('local')->put('document-generator/template-2027.docx', 'template-2027-content');

        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_number' => 2,
            'row_data' => ['SEC REGISTRATION DATE' => '7/23/2026 00:00:00'],
            'status' => 'queued',
        ]);

        $docxService = Mockery::mock(DocxTemplateService::class);
        $docxService->shouldReceive('placeholderKeys')
            ->once()
            ->with(Mockery::on(static fn (string $templatePath): bool => str_ends_with($templatePath, 'document-generator/template-2025.docx')))
            ->andReturn([]);
        $docxService->shouldReceive('validateRowData')
            ->once()
            ->withArgs(function (string $templatePath, ...$args): bool {
                return str_ends_with($templatePath, 'document-generator/template-2025.docx');
            })
            ->andReturn(['missing_data' => [], 'errors' => []]);
        $docxService->shouldReceive('render')
            ->once()
            ->withArgs(function (string $templatePath, string $outputPath, array $rowData, ?int $selectedTemplateYear = null): bool {
                return str_ends_with($templatePath, 'document-generator/template-2025.docx');
            })
            ->andReturnUsing(function (string $templatePath, string $outputPath, array $rowData = [], ?int $selectedTemplateYear = null): void {
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
    }

    public function test_job_accepts_multiple_sec_registration_date_formats_and_header_cases(): void
    {
        Storage::fake('local');

        $batch = $this->createBatchWithTemplate();
        DocumentBatchTemplate::factory()->create([
            'document_batch_id' => $batch->id,
            'year' => 2025,
            'template_name' => 'template-2025.docx',
            'template_path' => 'document-generator/template-2025.docx',
        ]);
        Storage::disk('local')->put('document-generator/template-2025.docx', 'template-2025-content');

        foreach ([
            ['SEC REGISTRATION DATE', '2025-07-23'],
            ['sec registration date', '07-23-2025'],
            ['Sec/Registration Date', '2025/07/23'],
            ['SEC REGISTRATION DATE', '07/23/2025 00:00:00'],
            ['SEC REGISTRATION DATE', '10/3/2025 0:00'],
            ['SEC REGISTRATION DATE', '10/03/2025 0:00'],
            ['SEC REGISTRATION DATE', '10/3/2025 00:00'],
            ['SEC REGISTRATION DATE', '2025.7.3 0:00'],
            ['SEC REGISTRATION DATE', '2025.07.23 00:00'],
        ] as [$header, $dateValue]) {
            $item = DocumentBatchItem::factory()->create([
                'document_batch_id' => $batch->id,
                'row_number' => fake()->numberBetween(2, 999),
                'row_data' => [$header => $dateValue],
                'status' => 'queued',
            ]);

            $docxService = Mockery::mock(DocxTemplateService::class);
            $docxService->shouldReceive('placeholderKeys')
                ->once()
                ->with(Mockery::on(static fn (string $templatePath): bool => str_ends_with($templatePath, 'document-generator/template-2025.docx')))
                ->andReturn([]);
            $docxService->shouldReceive('validateRowData')->once()->andReturn(['missing_data' => [], 'errors' => []]);
            $docxService->shouldReceive('render')
                ->once()
                ->andReturnUsing(function (string $templatePath, string $outputPath, array $rowData = [], ?int $selectedTemplateYear = null): void {
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
        }
    }

    public function test_job_fails_when_sec_registration_date_is_missing_or_invalid(): void
    {
        Storage::fake('local');

        $batch = $this->createBatchWithTemplate();
        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_number' => 2,
            'row_data' => ['SEC REGISTRATION DATE' => 'not-a-date'],
            'status' => 'queued',
        ]);

        $docxService = Mockery::mock(DocxTemplateService::class);
        $docxService->shouldNotReceive('validateRowData');
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
        $this->assertStringContainsString('Invalid SEC REGISTRATION DATE', (string) $item->error_message);
    }

    public function test_owner_can_soft_delete_a_batch_and_its_items(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $batch = DocumentBatch::factory()->for($user)->create();
        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
        ]);

        $this->deleteJson(route('document-generator.batches.destroy', $batch))
            ->assertOk()
            ->assertJsonPath('message', 'Batch deleted.');

        $this->assertSoftDeleted('document_batches', ['id' => $batch->id]);
        $this->assertSoftDeleted('document_batch_items', ['id' => $item->id]);

        $this->getJson(route('document-generator.batches.history'))
            ->assertOk()
            ->assertJsonMissing(['id' => $batch->id]);
    }

    public function test_owner_can_soft_delete_a_batch_item_and_batch_totals_are_recalculated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $batch = DocumentBatch::factory()->for($user)->create([
            'total_items' => 2,
            'processed_items' => 2,
            'success_items' => 1,
            'failed_items' => 1,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $deletedItem = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'pdf_done',
            'completed_at' => now(),
        ]);
        DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'status' => 'failed',
            'completed_at' => now(),
        ]);

        $this->deleteJson(route('document-generator.batches.items.destroy', [$batch, $deletedItem]))
            ->assertOk()
            ->assertJsonPath('message', 'Batch item deleted.');

        $this->assertSoftDeleted('document_batch_items', ['id' => $deletedItem->id]);

        $batch->refresh();
        $this->assertSame(1, $batch->total_items);
        $this->assertSame(1, $batch->processed_items);
        $this->assertSame(0, $batch->success_items);
        $this->assertSame(1, $batch->failed_items);
        $this->assertSame('failed', $batch->status);

        $this->getJson(route('document-generator.batches.items', $batch))
            ->assertOk()
            ->assertJsonMissing(['id' => $deletedItem->id]);
    }

    public function test_user_cannot_delete_another_users_batch_or_item(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $batch = DocumentBatch::factory()->for($owner)->create();
        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
        ]);

        $this->actingAs($intruder)
            ->deleteJson(route('document-generator.batches.destroy', $batch))
            ->assertNotFound();

        $this->actingAs($intruder)
            ->deleteJson(route('document-generator.batches.items.destroy', [$batch, $item]))
            ->assertNotFound();
    }

    public function test_deleting_an_already_deleted_batch_or_item_returns_not_found(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $batch = DocumentBatch::factory()->for($user)->create();
        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
        ]);

        $item->delete();
        $batch->delete();

        $this->deleteJson(route('document-generator.batches.destroy', $batch->id))
            ->assertNotFound();

        $this->deleteJson(route('document-generator.batches.items.destroy', [$batch->id, $item->id]))
            ->assertNotFound();
    }

    private function createBatchWithTemplate(?User $owner = null, array $attributes = []): DocumentBatch
    {
        Storage::disk('local')->put('document-generator/template.docx', 'template-content');

        $batch = DocumentBatch::factory()->for($owner ?? User::factory())->create(array_merge([
            'template_path' => 'document-generator/template.docx',
            'status' => 'queued',
            'total_items' => 1,
            'processed_items' => 0,
            'success_items' => 0,
            'failed_items' => 0,
        ], $attributes));

        DocumentBatchTemplate::factory()->create([
            'document_batch_id' => $batch->id,
            'year' => null,
            'template_name' => $batch->template_name,
            'template_path' => $batch->template_path,
        ]);

        return $batch;
    }

    /**
     * @param  list<string>  $placeholders
     */
    private function createBatchWithDocxTemplate(array $placeholders, ?User $owner = null, array $attributes = []): DocumentBatch
    {
        Storage::disk('local')->makeDirectory('document-generator');
        $this->writeDocxTemplate(Storage::disk('local')->path('document-generator/template.docx'), $placeholders);

        $batch = DocumentBatch::factory()->for($owner ?? User::factory())->create(array_merge([
            'template_path' => 'document-generator/template.docx',
            'status' => 'queued',
            'total_items' => 1,
            'processed_items' => 0,
            'success_items' => 0,
            'failed_items' => 0,
        ], $attributes));

        DocumentBatchTemplate::factory()->create([
            'document_batch_id' => $batch->id,
            'year' => null,
            'template_name' => $batch->template_name,
            'template_path' => $batch->template_path,
        ]);

        return $batch;
    }

    /**
     * @param  list<string>  $placeholders
     */
    private function addYearDocxTemplateToBatch(DocumentBatch $batch, int $year, array $placeholders, string $filename): DocumentBatchTemplate
    {
        $path = "document-generator/{$filename}";
        $this->writeDocxTemplate(Storage::disk('local')->path($path), $placeholders);

        return DocumentBatchTemplate::factory()->create([
            'document_batch_id' => $batch->id,
            'year' => $year,
            'template_name' => $filename,
            'template_path' => $path,
        ]);
    }

    /**
     * @param  list<string>  $placeholders
     */
    private function writeDocxTemplate(string $path, array $placeholders): void
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();

        foreach ($placeholders as $placeholder) {
            $section->addText('{'.$placeholder.'}');
        }

        WordIOFactory::createWriter($phpWord, 'Word2007')->save($path);
    }

    /**
     * @param  list<string>  $placeholders
     */
    private function createDocxUploadedFile(string $filename, array $placeholders): UploadedFile
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('docx-upload-', true).'-'.$filename;
        $this->writeDocxTemplate($path, $placeholders);

        return new UploadedFile(
            $path,
            $filename,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true
        );
    }

    private function readDocxDocumentXml(string $path): string
    {
        $zip = new ZipArchive;
        $result = $zip->open($path);

        $this->assertTrue($result === true, 'The generated DOCX file could not be opened.');

        $contents = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertIsString($contents);

        return $contents;
    }
}
