<?php

namespace Tests\Feature;

use App\Models\DocumentBatch;
use App\Models\DocumentBatchItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_dashboard_returns_empty_state_payload_for_a_new_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('summary.active_batches', 0)
                ->where('summary.rows_in_progress', 0)
                ->where('summary.documents_generated_30d', 0)
                ->where('summary.failed_rows_open', 0)
                ->where('summary.success_rate_30d', 0)
                ->has('active_batches', 0)
                ->has('recent_failures', 0)
                ->has('recent_activity', 0)
                ->has('recent_batches', 0),
            );
    }

    public function test_dashboard_aggregates_user_batch_metrics_and_orders_active_and_recent_batches(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $processingBatch = $this->createBatch($user, [
            'status' => 'processing',
            'total_items' => 5,
            'processed_items' => 2,
            'success_items' => 2,
            'failed_items' => 0,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $queuedBatch = $this->createBatch($user, [
            'status' => 'queued',
            'total_items' => 10,
            'processed_items' => 4,
            'success_items' => 3,
            'failed_items' => 1,
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        $completedBatch = $this->createBatch($user, [
            'status' => 'completed',
            'total_items' => 8,
            'processed_items' => 8,
            'success_items' => 8,
            'failed_items' => 0,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
            'completed_at' => now()->subHours(20),
        ]);

        $failedBatch = $this->createBatch($user, [
            'status' => 'failed',
            'total_items' => 6,
            'processed_items' => 6,
            'success_items' => 4,
            'failed_items' => 2,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
            'completed_at' => now()->subDays(2)->addHour(),
        ]);

        $oldBatch = $this->createBatch($user, [
            'status' => 'completed',
            'total_items' => 9,
            'processed_items' => 9,
            'success_items' => 9,
            'failed_items' => 0,
            'created_at' => now()->subDays(45),
            'updated_at' => now()->subDays(45),
            'completed_at' => now()->subDays(44),
        ]);

        $this->createBatch($otherUser, [
            'status' => 'processing',
            'total_items' => 99,
            'processed_items' => 1,
            'success_items' => 1,
            'failed_items' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createFailedItem($failedBatch, [
            'row_number' => 7,
            'row_data' => ['Company Name' => 'Northwind'],
            'error_message' => 'Missing tax id',
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->createFailedItem($failedBatch, [
            'row_number' => 8,
            'row_data' => ['Company' => 'Contoso'],
            'error_message' => 'Blank company address',
            'updated_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('summary.active_batches', 2)
                ->where('summary.rows_in_progress', 9)
                ->where('summary.documents_generated_30d', 17)
                ->where('summary.failed_rows_open', 2)
                ->where('summary.success_rate_30d', 85)
                ->has('active_batches', 2)
                ->where('active_batches.0.id', $processingBatch->id)
                ->where('active_batches.1.id', $queuedBatch->id)
                ->has('recent_batches', 5)
                ->where('recent_batches.0.id', $processingBatch->id)
                ->where('recent_batches.1.id', $queuedBatch->id)
                ->where('recent_batches.2.id', $completedBatch->id)
                ->where('recent_batches.3.id', $failedBatch->id)
                ->where('recent_batches.4.id', $oldBatch->id),
            );
    }

    public function test_dashboard_recent_failures_are_row_based_newest_first_and_scoped_to_the_signed_in_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $failedBatch = $this->createBatch($user, [
            'status' => 'failed',
            'processed_items' => 6,
            'success_items' => 0,
            'failed_items' => 6,
        ]);

        $otherFailedBatch = $this->createBatch($otherUser, [
            'status' => 'failed',
            'processed_items' => 1,
            'success_items' => 0,
            'failed_items' => 1,
        ]);

        $recentItems = [];
        for ($index = 1; $index <= 6; $index++) {
            $recentItems[] = $this->createFailedItem($failedBatch, [
                'row_number' => 20 + $index,
                'row_data' => ['Company Name' => "Company {$index}"],
                'error_message' => "Failure {$index}",
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        $this->createFailedItem($otherFailedBatch, [
            'row_number' => 999,
            'row_data' => ['Company Name' => 'Do Not Show'],
            'error_message' => 'Other user failure',
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('recent_failures', 5)
                ->where('recent_failures.0.item_id', $recentItems[0]->id)
                ->where('recent_failures.0.row_number', 21)
                ->where('recent_failures.0.company', 'Company 1')
                ->where('recent_failures.1.item_id', $recentItems[1]->id)
                ->where('recent_failures.4.item_id', $recentItems[4]->id)
                ->where('recent_failures.4.row_number', 25),
            );
    }

    public function test_dashboard_recent_activity_is_newest_first_limited_and_maps_missing_users_to_system(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $batch = $this->createBatch($user, ['status' => 'completed']);
        $item = DocumentBatchItem::factory()->create([
            'document_batch_id' => $batch->id,
            'row_number' => 42,
            'status' => 'pdf_done',
        ]);

        $otherBatch = $this->createBatch($otherUser, ['status' => 'completed']);
        $otherItem = DocumentBatchItem::factory()->create([
            'document_batch_id' => $otherBatch->id,
            'row_number' => 99,
            'status' => 'pdf_done',
        ]);

        $item->activityLogs()->create([
            'document_batch_id' => $batch->id,
            'user_id' => $user->id,
            'action' => 'discarded_oldest',
            'summary' => 'Discard me',
            'details' => [],
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ]);

        for ($index = 1; $index <= 7; $index++) {
            $timestamp = now()->subMinutes(9 - $index);

            $item->activityLogs()->create([
                'document_batch_id' => $batch->id,
                'user_id' => $user->id,
                'action' => "user_action_{$index}",
                'summary' => "Entry {$index}",
                'details' => [],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        $item->activityLogs()->create([
            'document_batch_id' => $batch->id,
            'user_id' => null,
            'action' => 'system_action',
            'summary' => 'System entry',
            'details' => [],
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $otherItem->activityLogs()->create([
            'document_batch_id' => $otherBatch->id,
            'user_id' => $otherUser->id,
            'action' => 'other_user_action',
            'summary' => 'Should not appear',
            'details' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('recent_activity', 8)
                ->where('recent_activity.0.user_name', 'System')
                ->where('recent_activity.0.summary', 'System entry')
                ->where('recent_activity.0.row_number', 42)
                ->where('recent_activity.1.summary', 'Entry 7')
                ->where('recent_activity.7.summary', 'Entry 1'),
            );
    }

    private function createBatch(User $user, array $attributes = []): DocumentBatch
    {
        return DocumentBatch::factory()->for($user)->create($attributes);
    }

    private function createFailedItem(DocumentBatch $batch, array $attributes = []): DocumentBatchItem
    {
        return DocumentBatchItem::factory()->create(array_merge([
            'document_batch_id' => $batch->id,
            'status' => 'failed',
            'error_message' => 'Generation failed',
            'updated_at' => now(),
        ], $attributes));
    }
}
