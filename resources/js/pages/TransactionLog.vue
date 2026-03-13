<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { computed, onMounted, ref } from 'vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { DataTable } from '@/components/ui/data-table';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type ActivityLog = {
    id: number;
    action: string;
    summary: string;
    details: Record<string, unknown>;
    created_at: string | null;
    row_number: number | null;
    batch: {
        id: number;
        source_excel_name: string;
    } | null;
    user: {
        id: number;
        name: string;
    } | null;
};

type PaginatedResponse<T> = {
    current_page: number;
    data: T[];
    last_page: number;
    per_page: number;
    total: number;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Transaction Log',
        href: '/transaction-log',
    },
];

const activityData = ref<PaginatedResponse<ActivityLog>>({
    current_page: 1,
    data: [],
    last_page: 1,
    per_page: 10,
    total: 0,
});
const activityLoading = ref(false);

const getApi = async <T>(url: string): Promise<T> => {
    const response = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
};

const loadActivityLogs = async (page = activityData.value.current_page) => {
    activityLoading.value = true;
    try {
        const query = new URLSearchParams({
            page: String(page),
            per_page: String(activityData.value.per_page),
        });
        activityData.value = await getApi<PaginatedResponse<ActivityLog>>(`/transaction-log/logs?${query.toString()}`);
    } finally {
        activityLoading.value = false;
    }
};

const activityColumns = computed<ColumnDef<ActivityLog>[]>(() => [
    {
        id: 'created_at',
        accessorKey: 'created_at',
        header: 'When',
        enableSorting: false,
        cell: ({ row }) => (row.original.created_at ? new Date(row.original.created_at).toLocaleString() : '-'),
    },
    {
        id: 'user',
        header: 'User',
        enableSorting: false,
        cell: ({ row }) => row.original.user?.name ?? 'System',
    },
    {
        id: 'batch',
        header: 'Batch',
        enableSorting: false,
        cell: ({ row }) => (row.original.batch ? `#${row.original.batch.id}` : '-'),
    },
    {
        id: 'row_number',
        accessorKey: 'row_number',
        header: 'Row',
        enableSorting: false,
        cell: ({ row }) => row.original.row_number ?? '-',
    },
    {
        id: 'action',
        accessorKey: 'action',
        header: 'Action',
        enableSorting: false,
    },
    {
        id: 'summary',
        accessorKey: 'summary',
        header: 'Summary',
        enableSorting: false,
    },
]);

onMounted(async () => {
    await loadActivityLogs(1);
});
</script>

<template>
    <Head title="Transaction Log" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <Card>
                <CardHeader>
                    <CardTitle>Transaction Log</CardTitle>
                    <CardDescription>Shared activity for edits, regenerations, and validation failures.</CardDescription>
                </CardHeader>
                <CardContent>
                    <DataTable
                        :columns="activityColumns"
                        :data="activityData.data"
                        :meta="activityData"
                        :loading="activityLoading"
                        sort-by="created_at"
                        sort-direction="desc"
                        empty-message="No activity recorded."
                        @page-change="loadActivityLogs"
                        @per-page-change="
                            async (perPage) => {
                                activityData.per_page = perPage;
                                await loadActivityLogs(1);
                            }
                        "
                    />
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
