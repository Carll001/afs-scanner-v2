<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import BatchItemsPanel from '@/components/generated-files/BatchItemsPanel.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type BatchSummary = {
    id: number;
    uuid: string;
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

const props = defineProps<{
    batch: BatchSummary;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Generated Files',
        href: '/generated-files',
    },
    {
        title: `Batch #${props.batch.id}`,
        href: `/generated-files/${props.batch.uuid}`,
    },
];
</script>

<template>
    <Head :title="`Batch #${batch.id} Files`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <Button variant="outline" as-child>
                <Link href="/generated-files">Back to Batch Folders</Link>
            </Button>

            <BatchItemsPanel :batch="batch" />
        </div>
    </AppLayout>
</template>
