<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BatchFoldersList from '@/components/generated-files/BatchFoldersList.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type HistoryBatch = {
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

type PaginatedResponse<T> = {
    current_page: number;
    data: T[];
    last_page: number;
    per_page: number;
    total: number;
};

defineProps<{
    initialHistory: PaginatedResponse<HistoryBatch>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Generated Files',
        href: '/generated-files',
    },
];
</script>

<template>
    <Head title="Generated Files" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <BatchFoldersList :initial-history="initialHistory" />
        </div>
    </AppLayout>
</template>
