<?php

namespace Tests\Feature;

use App\Models\DocumentBatch;
use App\Models\DocumentGeneratorTemplate;
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

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('stats')
                ->has('recent_batches')
                ->has('template_summary')
            );
    }

    public function test_dashboard_shows_document_generator_stats_for_authenticated_users(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        DocumentBatch::factory()->for($user)->create([
            'status' => 'queued',
            'success_items' => 1,
        ]);
        DocumentBatch::factory()->for($user)->create([
            'status' => 'processing',
            'success_items' => 2,
        ]);
        DocumentBatch::factory()->for($user)->create([
            'status' => 'completed',
            'success_items' => 3,
        ]);
        DocumentBatch::factory()->for($user)->create([
            'status' => 'failed',
            'success_items' => 4,
        ]);
        DocumentBatch::factory()->for($otherUser)->create([
            'status' => 'completed',
            'success_items' => 99,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('stats.total_batches', 4)
                ->where('stats.active_batches', 2)
                ->where('stats.completed_batches', 1)
                ->where('stats.failed_batches', 1)
                ->where('stats.total_generated_files', 20)
                ->has('recent_batches', 4)
            );
    }

    public function test_dashboard_shows_template_summary_states(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('template_summary.default_template_present', false)
                ->where('template_summary.default_template_name', null)
                ->where('template_summary.year_rule_count', 0)
            );

        DocumentGeneratorTemplate::factory()->create([
            'year' => null,
            'template_name' => 'default.docx',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('template_summary.default_template_present', true)
                ->where('template_summary.default_template_name', 'default.docx')
                ->where('template_summary.year_rule_count', 0)
            );

        DocumentGeneratorTemplate::factory()->create([
            'year' => 2025,
            'template_name' => '2025.docx',
        ]);
        DocumentGeneratorTemplate::factory()->create([
            'year' => 2027,
            'template_name' => '2027.docx',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('template_summary.default_template_present', true)
                ->where('template_summary.default_template_name', 'default.docx')
                ->where('template_summary.year_rule_count', 2)
            );
    }
}
