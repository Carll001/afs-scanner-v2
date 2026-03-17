<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import documentGeneratorRoutes from '@/routes/document-generator';
import generatedFilesRoutes from '@/routes/generated-files';
import type { BreadcrumbItem } from '@/types';

type DashboardStats = {
    total_batches: number;
    active_batches: number;
    completed_batches: number;
    failed_batches: number;
    total_generated_files: number;
};

type RecentBatch = {
    id: number;
    source_excel_name: string;
    template_name: string;
    status: string;
    total_items: number;
    processed_items: number;
    success_items: number;
    failed_items: number;
    created_at: string | null;
    completed_at: string | null;
};

type TemplateSummary = {
    default_template_present: boolean;
    default_template_name: string | null;
    year_rule_count: number;
};

const props = defineProps<{
    stats: DashboardStats;
    recent_batches: RecentBatch[];
    template_summary: TemplateSummary;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
    },
];

const statCards = computed(() => [
    { label: 'Total Batches', value: props.stats.total_batches, tone: 'default' as const },
    { label: 'Active Batches', value: props.stats.active_batches, tone: 'secondary' as const },
    { label: 'Completed Batches', value: props.stats.completed_batches, tone: 'default' as const },
    { label: 'Failed Batches', value: props.stats.failed_batches, tone: 'destructive' as const },
    { label: 'Generated Files', value: props.stats.total_generated_files, tone: 'outline' as const },
]);

const statusBadgeVariant = (status: string): 'default' | 'secondary' | 'destructive' | 'outline' => {
    if (status === 'failed') {
        return 'destructive';
    }

    if (status === 'completed') {
        return 'default';
    }

    if (status === 'processing') {
        return 'secondary';
    }

    return 'outline';
};

const formatDate = (value: string | null) => (value ? new Date(value).toLocaleString() : 'Not finished yet');
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <Card class="overflow-hidden border-none bg-gradient-to-br from-slate-950 via-slate-900 to-slate-800 text-slate-50 shadow-sm">
                <CardContent class="grid gap-6 px-6 py-8 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                    <div class="space-y-3">
                        <div class="inline-flex rounded-full border border-slate-700/80 bg-slate-900/70 px-3 py-1 text-xs font-medium tracking-wide text-slate-300 uppercase">
                            Document Operations
                        </div>
                        <div class="space-y-2">
                            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">Document Generator Dashboard</h1>
                            <p class="max-w-2xl text-sm leading-6 text-slate-300 sm:text-base">
                                Monitor recent document batches, check template readiness, and jump straight into the tools
                                your team uses to generate and manage files.
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row lg:flex-col">
                        <Button as-child class="bg-white text-slate-950 hover:bg-slate-100">
                            <Link :href="documentGeneratorRoutes.index()">Open Document Generator</Link>
                        </Button>
                        <Button as-child variant="secondary" class="border-slate-700 bg-slate-800 text-slate-100 hover:bg-slate-700">
                            <Link :href="generatedFilesRoutes.index()">View Generated Files</Link>
                        </Button>
                        <Button as-child variant="outline" class="border-slate-600 text-slate-100 hover:bg-slate-800">
                            <Link :href="documentGeneratorRoutes.templateMapping()">Template Mapping</Link>
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <Card
                    v-for="stat in statCards"
                    :key="stat.label"
                    class="border bg-card/95 shadow-sm"
                >
                    <CardContent class="p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    {{ stat.label }}
                                </p>
                                <p class="mt-3 text-3xl font-semibold tracking-tight">
                                    {{ stat.value }}
                                </p>
                            </div>
                            <Badge :variant="stat.tone">
                                {{ stat.label.split(' ')[0] }}
                            </Badge>
                        </div>
                    </CardContent>
                </Card>
            </section>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,1fr)]">
                <Card>
                    <CardHeader class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle>Recent Batches</CardTitle>
                            <CardDescription>
                                Latest document-generation runs across your workspace.
                            </CardDescription>
                        </div>
                        <Button as-child variant="outline">
                            <Link :href="generatedFilesRoutes.index()">Open All Batches</Link>
                        </Button>
                    </CardHeader>
                    <CardContent>
                        <div v-if="recent_batches.length === 0" class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">
                            No batches yet. Start one from the Document Generator workspace.
                        </div>

                        <div v-else class="space-y-3">
                            <div
                                v-for="batch in recent_batches"
                                :key="batch.id"
                                class="rounded-xl border p-4"
                            >
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="space-y-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="font-medium">Batch #{{ batch.id }}</p>
                                            <Badge :variant="statusBadgeVariant(batch.status)">{{ batch.status }}</Badge>
                                        </div>
                                        <p class="text-sm text-muted-foreground">{{ batch.source_excel_name }}</p>
                                        <p class="text-sm text-muted-foreground">Template: {{ batch.template_name }}</p>
                                    </div>

                                    <Button as-child variant="outline" size="sm">
                                        <Link :href="generatedFilesRoutes.show({ batch: batch.id })">Open</Link>
                                    </Button>
                                </div>

                                <div class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                                    <div class="rounded-lg bg-muted/40 p-3">
                                        <p class="text-xs text-muted-foreground uppercase">Processed</p>
                                        <p class="mt-1 font-medium">{{ batch.processed_items }}/{{ batch.total_items }}</p>
                                    </div>
                                    <div class="rounded-lg bg-muted/40 p-3">
                                        <p class="text-xs text-muted-foreground uppercase">Success / Failed</p>
                                        <p class="mt-1 font-medium">{{ batch.success_items }} / {{ batch.failed_items }}</p>
                                    </div>
                                    <div class="rounded-lg bg-muted/40 p-3">
                                        <p class="text-xs text-muted-foreground uppercase">Last Updated</p>
                                        <p class="mt-1 font-medium">{{ formatDate(batch.completed_at ?? batch.created_at) }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div class="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Template Setup</CardTitle>
                            <CardDescription>
                                Current global template readiness for future batches.
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div class="rounded-xl border p-4">
                                <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Default Template</p>
                                <p class="mt-2 text-lg font-semibold tracking-tight">
                                    {{ template_summary.default_template_present ? 'Configured' : 'Missing' }}
                                </p>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    {{ template_summary.default_template_name ?? 'No default template configured yet.' }}
                                </p>
                            </div>

                            <div class="rounded-xl border p-4">
                                <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Year Rules</p>
                                <p class="mt-2 text-lg font-semibold tracking-tight">{{ template_summary.year_rule_count }}</p>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Threshold-based year templates are ready for future batches.
                                </p>
                            </div>

                            <Button as-child class="w-full">
                                <Link :href="documentGeneratorRoutes.templateMapping()">Manage Templates</Link>
                            </Button>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Workflow Shortcuts</CardTitle>
                            <CardDescription>
                                Jump to the next step without leaving the dashboard.
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <Link
                                :href="documentGeneratorRoutes.index()"
                                class="block rounded-xl border p-4 transition-colors hover:bg-muted/50"
                            >
                                <p class="font-medium">Create New Batch</p>
                                <p class="text-sm text-muted-foreground">
                                    Upload Excel sources and start a new generation run.
                                </p>
                            </Link>
                            <Link
                                :href="generatedFilesRoutes.index()"
                                class="block rounded-xl border p-4 transition-colors hover:bg-muted/50"
                            >
                                <p class="font-medium">Review Generated Files</p>
                                <p class="text-sm text-muted-foreground">
                                    Check completed batches, outputs, and batch-level details.
                                </p>
                            </Link>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
