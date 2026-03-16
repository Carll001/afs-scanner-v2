<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Activity, ArrowRight, CircleCheckBig, FileStack, Rows3, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import documentGenerator from '@/routes/document-generator';
import type { BreadcrumbItem } from '@/types';

type Summary = {
    active_batches: number;
    rows_in_progress: number;
    documents_generated_30d: number;
    failed_rows_open: number;
    success_rate_30d: number;
};

type DashboardBatch = {
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

type RecentFailure = {
    batch_id: number;
    item_id: number;
    row_number: number;
    company: string;
    error_message: string;
    updated_at: string | null;
};

type RecentActivity = {
    id: number;
    batch_id: number;
    row_number: number | null;
    action: string;
    summary: string;
    user_name: string;
    created_at: string | null;
};

const props = defineProps<{
    summary: Summary;
    active_batches: DashboardBatch[];
    recent_failures: RecentFailure[];
    recent_activity: RecentActivity[];
    recent_batches: DashboardBatch[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
    },
];

const generatorNewBatchHref = `${documentGenerator.index().url}#new-batch`;

const hasRecentWork = computed(
    () =>
        props.active_batches.length > 0 ||
        props.recent_failures.length > 0 ||
        props.recent_activity.length > 0 ||
        props.recent_batches.length > 0,
);

const statusBadgeVariant = (status: string): 'default' | 'secondary' | 'destructive' | 'outline' => {
    if (status === 'failed') {
        return 'destructive';
    }

    if (status === 'pdf_done' || status === 'completed') {
        return 'default';
    }

    if (status === 'processing' || status === 'docx_done') {
        return 'secondary';
    }

    return 'outline';
};

const formatDateTime = (value: string | null) => {
    if (!value) {
        return 'Not available';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
};

const progressPercent = (batch: DashboardBatch) => {
    if (batch.total_items === 0) {
        return 100;
    }

    return Math.min(100, Math.floor((batch.processed_items / batch.total_items) * 100));
};

const summarizeError = (value: string) => {
    if (value.length <= 120) {
        return value;
    }

    return `${value.slice(0, 117)}...`;
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <Card class="overflow-hidden border-sidebar-border/80 bg-gradient-to-br from-primary/8 via-background to-background">
                <CardHeader class="gap-4 md:flex-row md:items-end md:justify-between">
                    <div class="space-y-2">
                        <Badge variant="outline" class="w-fit">Operations Overview</Badge>
                        <CardTitle class="text-2xl">Personal Operations Dashboard</CardTitle>
                        <CardDescription class="max-w-2xl text-sm leading-6">
                            Monitor active runs, unblock failed rows, and jump back into document generation without
                            re-opening the full workspace first.
                        </CardDescription>
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row">
                        <Button as-child>
                            <Link :href="generatorNewBatchHref">Start New Batch</Link>
                        </Button>
                        <Button variant="outline" as-child>
                            <Link :href="documentGenerator.index()">Open Document Generator</Link>
                        </Button>
                    </div>
                </CardHeader>
                <CardContent class="flex flex-col gap-3 border-t border-border/60 pt-6 text-sm text-muted-foreground md:flex-row md:items-center md:justify-between">
                    <p v-if="hasRecentWork">
                        Generated <span class="font-semibold text-foreground">{{ summary.documents_generated_30d }}</span>
                        documents in the last 30 days with a
                        <span class="font-semibold text-foreground">{{ summary.success_rate_30d }}%</span> success rate.
                    </p>
                    <p v-else>No batches yet. Start your first upload to populate this dashboard.</p>
                    <p class="text-xs uppercase tracking-[0.2em] text-muted-foreground/80">Last 30 days</p>
                </CardContent>
            </Card>

            <div class="grid items-start gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Card>
                    <CardHeader class="flex flex-row items-start justify-between space-y-0">
                        <div class="space-y-1">
                            <CardDescription>Active Batches</CardDescription>
                            <CardTitle class="text-3xl">{{ summary.active_batches }}</CardTitle>
                        </div>
                        <div class="rounded-full bg-primary/10 p-2 text-primary">
                            <FileStack class="size-5" />
                        </div>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">
                        Currently queued or processing batches.
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-start justify-between space-y-0">
                        <div class="space-y-1">
                            <CardDescription>Rows In Progress</CardDescription>
                            <CardTitle class="text-3xl">{{ summary.rows_in_progress }}</CardTitle>
                        </div>
                        <div class="rounded-full bg-primary/10 p-2 text-primary">
                            <Rows3 class="size-5" />
                        </div>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">
                        Remaining rows inside active batches.
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-start justify-between space-y-0">
                        <div class="space-y-1">
                            <CardDescription>Documents Generated</CardDescription>
                            <CardTitle class="text-3xl">{{ summary.documents_generated_30d }}</CardTitle>
                        </div>
                        <div class="rounded-full bg-primary/10 p-2 text-primary">
                            <CircleCheckBig class="size-5" />
                        </div>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">
                        {{ summary.success_rate_30d }}% success rate over the last 30 days.
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-start justify-between space-y-0">
                        <div class="space-y-1">
                            <CardDescription>Failed Rows Open</CardDescription>
                            <CardTitle class="text-3xl">{{ summary.failed_rows_open }}</CardTitle>
                        </div>
                        <div class="rounded-full bg-destructive/10 p-2 text-destructive">
                            <TriangleAlert class="size-5" />
                        </div>
                    </CardHeader>
                    <CardContent class="text-sm text-muted-foreground">
                        Rows that still need review or regeneration.
                    </CardContent>
                </Card>
            </div>

            <div class="flex flex-col gap-4 xl:grid xl:grid-cols-[1.2fr_0.8fr] xl:items-start">
                <div class="contents xl:flex xl:flex-col xl:gap-4">
                    <Card class="order-1 xl:order-none">
                        <CardHeader class="flex flex-row items-start justify-between space-y-0">
                            <div>
                                <CardTitle>Active Batches</CardTitle>
                                <CardDescription>Newest queued or processing batches.</CardDescription>
                            </div>
                            <Badge variant="outline">{{ active_batches.length }} shown</Badge>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div v-if="active_batches.length === 0" class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">
                                No active batches. Recent work will appear here when you start or reopen a run.
                            </div>

                            <div v-for="batch in active_batches" :key="batch.id" class="rounded-xl border bg-muted/25 p-4">
                                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                    <div class="space-y-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="font-semibold">Batch #{{ batch.id }}</h3>
                                            <Badge :variant="statusBadgeVariant(batch.status)">
                                                {{ batch.status }}
                                            </Badge>
                                        </div>
                                        <p class="text-sm text-foreground">{{ batch.source_excel_name }}</p>
                                        <p class="text-sm text-muted-foreground">{{ batch.template_name }}</p>
                                    </div>

                                    <Button variant="outline" as-child>
                                        <Link :href="documentGenerator.index({ query: { batch: batch.id } })">
                                            Open batch
                                        </Link>
                                    </Button>
                                </div>

                                <div class="mt-4 space-y-3">
                                    <div class="h-2 overflow-hidden rounded-full bg-muted">
                                        <div class="h-full bg-primary transition-all" :style="{ width: `${progressPercent(batch)}%` }" />
                                    </div>

                                    <div class="grid gap-2 text-sm text-muted-foreground sm:grid-cols-2 xl:grid-cols-4">
                                        <p>Processed <span class="font-medium text-foreground">{{ batch.processed_items }}/{{ batch.total_items }}</span></p>
                                        <p>Success <span class="font-medium text-foreground">{{ batch.success_items }}</span></p>
                                        <p>Failed <span class="font-medium text-foreground">{{ batch.failed_items }}</span></p>
                                        <p>Created <span class="font-medium text-foreground">{{ formatDateTime(batch.created_at) }}</span></p>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card class="order-3 xl:order-none">
                        <CardHeader class="flex flex-row items-start justify-between space-y-0">
                            <div>
                                <CardTitle>Recent Activity</CardTitle>
                                <CardDescription>Latest row-level events across your batches.</CardDescription>
                            </div>
                            <div class="rounded-full bg-primary/10 p-2 text-primary">
                                <Activity class="size-5" />
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <div v-if="recent_activity.length === 0" class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">
                                No activity recorded yet.
                            </div>

                            <div
                                v-for="entry in recent_activity"
                                :key="entry.id"
                                class="flex flex-col gap-3 rounded-xl border p-4 md:flex-row md:items-start md:justify-between"
                            >
                                <div class="space-y-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <Badge variant="outline">{{ entry.action }}</Badge>
                                        <span class="text-sm font-medium text-foreground">{{ entry.user_name }}</span>
                                        <span v-if="entry.row_number !== null" class="text-sm text-muted-foreground">Row {{ entry.row_number }}</span>
                                    </div>
                                    <p class="text-sm text-foreground">{{ entry.summary }}</p>
                                    <p class="text-xs text-muted-foreground">{{ formatDateTime(entry.created_at) }}</p>
                                </div>

                                <Button variant="ghost" size="sm" as-child class="justify-start md:justify-center">
                                    <Link :href="documentGenerator.index({ query: { batch: entry.batch_id } })">
                                        Open batch
                                    </Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div class="contents xl:flex xl:flex-col xl:gap-4">
                    <Card class="order-2 xl:order-none">
                        <CardHeader class="flex flex-row items-start justify-between space-y-0">
                            <div>
                                <CardTitle>Needs Attention</CardTitle>
                                <CardDescription>Newest failed rows that still need action.</CardDescription>
                            </div>
                            <Badge variant="destructive">{{ recent_failures.length }}</Badge>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div v-if="recent_failures.length === 0" class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">
                                No failed rows right now.
                            </div>

                            <div v-for="failure in recent_failures" :key="failure.item_id" class="rounded-xl border border-destructive/20 bg-destructive/5 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="space-y-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-semibold">Batch #{{ failure.batch_id }}</span>
                                            <Badge variant="destructive">Row {{ failure.row_number }}</Badge>
                                        </div>
                                        <p class="text-sm text-foreground">{{ failure.company || 'Unknown company' }}</p>
                                        <p class="text-sm text-muted-foreground">{{ summarizeError(failure.error_message) }}</p>
                                        <p class="text-xs text-muted-foreground">Updated {{ formatDateTime(failure.updated_at) }}</p>
                                    </div>

                                    <Button variant="outline" size="sm" as-child>
                                        <Link
                                            :href="documentGenerator.index({ query: { batch: failure.batch_id, status: 'failed' } })"
                                        >
                                            Review failed rows
                                        </Link>
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card class="order-4 xl:order-none">
                        <CardHeader class="flex flex-row items-start justify-between space-y-0">
                            <div>
                                <CardTitle>Recent Batches</CardTitle>
                                <CardDescription>Latest completed or historical runs.</CardDescription>
                            </div>
                            <ArrowRight class="size-5 text-muted-foreground" />
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <div v-if="recent_batches.length === 0" class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">
                                Batch history will appear here after your first run.
                            </div>

                            <div
                                v-for="batch in recent_batches"
                                :key="batch.id"
                                class="flex flex-col gap-3 rounded-xl border p-4 md:flex-row md:items-center md:justify-between"
                            >
                                <div class="space-y-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-semibold">Batch #{{ batch.id }}</span>
                                        <Badge :variant="statusBadgeVariant(batch.status)">
                                            {{ batch.status }}
                                        </Badge>
                                    </div>
                                    <p class="text-sm text-foreground">{{ batch.source_excel_name }}</p>
                                    <p class="text-sm text-muted-foreground">
                                        Generated {{ batch.success_items }}/{{ batch.total_items }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Created {{ formatDateTime(batch.created_at) }}
                                        <span v-if="batch.completed_at"> • Completed {{ formatDateTime(batch.completed_at) }}</span>
                                    </p>
                                </div>

                                <Button variant="outline" size="sm" as-child>
                                    <Link :href="documentGenerator.index({ query: { batch: batch.id } })">
                                        Open
                                    </Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
