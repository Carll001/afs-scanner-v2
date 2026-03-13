<script setup lang="ts">
import type { ColumnDef } from '@tanstack/vue-table';
import { Head } from '@inertiajs/vue3';
import { computed, h, onBeforeUnmount, reactive, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { DataTable } from '@/components/ui/data-table';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import documentGeneratorRoutes from '@/routes/document-generator';
import type { BreadcrumbItem } from '@/types';

type SortDirection = 'asc' | 'desc';

type BatchProgress = {
    batch_id: number;
    status: string;
    total_items: number;
    processed_items: number;
    success_items: number;
    failed_items: number;
    progress_percent: number;
};

type BatchItem = {
    id: number;
    row_number: number;
    company: string;
    status: string;
    row_data: Record<string, string>;
    docx_available: boolean;
    pdf_available: boolean;
    error_message: string | null;
    created_at: string | null;
    updated_at: string | null;
};

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

const props = defineProps<{
    initialHistory: PaginatedResponse<HistoryBatch>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Document Generator',
        href: documentGeneratorRoutes.index(),
    },
];

const excelFile = ref<File | null>(null);
const templateFile = ref<File | null>(null);
const sheetIndex = ref('0');
const createErrors = ref<Record<string, string[]>>({});
const createErrorMessage = ref<string | null>(null);
const creatingBatch = ref(false);

const activeBatchId = ref<number | null>(null);
const progress = ref<BatchProgress | null>(null);
const pollingActive = ref(false);

const itemsData = ref<PaginatedResponse<BatchItem>>({
    current_page: 1,
    data: [],
    last_page: 1,
    per_page: 10,
    total: 0,
});
const itemsLoading = ref(false);
const itemsSortBy = ref('row_number');
const itemsSortDirection = ref<SortDirection>('asc');
const itemStatusFilter = ref('all');
const companySearch = ref('');

const historyData = ref<PaginatedResponse<HistoryBatch>>(props.initialHistory);
const historyLoading = ref(false);
const historySortBy = ref('created_at');
const historySortDirection = ref<SortDirection>('desc');

const editDialogOpen = ref(false);
const editSubmitting = ref(false);
const editErrorMessage = ref<string | null>(null);
const editErrors = ref<Record<string, string[]>>({});
const editingItem = ref<BatchItem | null>(null);
const editForm = reactive<Record<string, string>>({});

let pollInterval: ReturnType<typeof setInterval> | null = null;
let companySearchDebounce: ReturnType<typeof setTimeout> | null = null;

const csrfToken = () => {
    const xsrfCookie = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='));

    if (!xsrfCookie) {
        return '';
    }

    return decodeURIComponent(xsrfCookie.split('=')[1] ?? '');
};

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

const sendJson = async <T>(url: string, method: 'PUT', payload: unknown): Promise<T> => {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
    });

    if (response.status === 422) {
        const errorPayload = (await response.json()) as {
            errors?: Record<string, string[]>;
            message?: string;
        };
        const validationError = new Error(errorPayload.message ?? 'Validation failed.');
        Object.assign(validationError, { validationErrors: errorPayload.errors ?? {} });
        throw validationError;
    }

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
};

const postBatch = async () => {
    if (!excelFile.value || !templateFile.value) {
        createErrorMessage.value = 'Excel file and DOCX template are required.';
        return;
    }

    creatingBatch.value = true;
    createErrors.value = {};
    createErrorMessage.value = null;

    try {
        const formData = new FormData();
        formData.append('excel_file', excelFile.value);
        formData.append('template_file', templateFile.value);
        formData.append('sheet_index', sheetIndex.value || '0');

        const response = await fetch(documentGeneratorRoutes.batches.store.url(), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
        });

        if (response.status === 422) {
            const payload = (await response.json()) as {
                errors?: Record<string, string[]>;
                message?: string;
            };

            createErrors.value = payload.errors ?? {};
            createErrorMessage.value = payload.message ?? 'Validation failed.';
            return;
        }

        if (!response.ok) {
            throw new Error(`Failed to create batch (${response.status}).`);
        }

        const payload = (await response.json()) as { batch_id: number };
        activeBatchId.value = payload.batch_id;

        await Promise.all([loadProgress(), loadBatchItems(1), loadHistory(1)]);
        startPolling();
    } catch (error) {
        createErrorMessage.value = error instanceof Error ? error.message : 'Unable to create batch.';
    } finally {
        creatingBatch.value = false;
    }
};

const onExcelFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    excelFile.value = input.files?.[0] ?? null;
};

const onTemplateFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    templateFile.value = input.files?.[0] ?? null;
};

const onItemStatusChange = async (value: string) => {
    itemStatusFilter.value = value;
    await loadBatchItems(1);
};

const onCompanySearchInput = (event: Event) => {
    const target = event.target as HTMLInputElement;
    companySearch.value = target.value;

    if (companySearchDebounce) {
        clearTimeout(companySearchDebounce);
    }

    companySearchDebounce = setTimeout(() => {
        void loadBatchItems(1);
    }, 300);
};

const loadProgress = async () => {
    if (!activeBatchId.value) {
        return;
    }

    progress.value = await getApi<BatchProgress>(
        documentGeneratorRoutes.batches.progress.url({ batch: activeBatchId.value }),
    );
};

const loadBatchItems = async (page = itemsData.value.current_page) => {
    if (!activeBatchId.value) {
        return;
    }

    itemsLoading.value = true;
    try {
        const query: Record<string, string | number> = {
            page,
            per_page: itemsData.value.per_page,
            sort_by: itemsSortBy.value,
            sort_direction: itemsSortDirection.value,
        };

        if (itemStatusFilter.value !== 'all') {
            query.status = itemStatusFilter.value;
        }
        if (companySearch.value.trim() !== '') {
            query.company_search = companySearch.value.trim();
        }

        itemsData.value = await getApi<PaginatedResponse<BatchItem>>(
            documentGeneratorRoutes.batches.items.url(
                { batch: activeBatchId.value },
                {
                    query,
                },
            ),
        );
    } finally {
        itemsLoading.value = false;
    }
};

const loadHistory = async (page = historyData.value.current_page) => {
    historyLoading.value = true;
    try {
        historyData.value = await getApi<PaginatedResponse<HistoryBatch>>(
            documentGeneratorRoutes.batches.history.url({
                query: {
                    page,
                    history_per_page: historyData.value.per_page,
                    sort_by: historySortBy.value,
                    sort_direction: historySortDirection.value,
                },
            }),
        );
    } finally {
        historyLoading.value = false;
    }
};

const stopPolling = () => {
    pollingActive.value = false;
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
};

const startPolling = () => {
    stopPolling();
    pollingActive.value = true;

    pollInterval = setInterval(async () => {
        try {
            await loadProgress();
            await loadBatchItems();

            if (progress.value && ['completed', 'failed'].includes(progress.value.status)) {
                stopPolling();
                await loadHistory(1);
            }
        } catch {
            stopPolling();
        }
    }, 2000);
};

const progressText = computed(() => {
    if (!progress.value) {
        return 'No active batch';
    }

    return `${progress.value.processed_items}/${progress.value.total_items} processed`;
});

const editFormEntries = computed(() => Object.entries(editForm));

const canEditItem = (item: BatchItem) => !['queued', 'processing'].includes(item.status);

const resetEditForm = () => {
    for (const key of Object.keys(editForm)) {
        delete editForm[key];
    }
};

const openEditDialog = (item: BatchItem) => {
    editingItem.value = item;
    editDialogOpen.value = true;
    editErrorMessage.value = null;
    editErrors.value = {};
    resetEditForm();

    for (const [key, value] of Object.entries(item.row_data)) {
        editForm[key] = value;
    }
};

const closeEditDialog = () => {
    editDialogOpen.value = false;
    editingItem.value = null;
    editErrorMessage.value = null;
    editErrors.value = {};
    resetEditForm();
};

const saveEditedItem = async () => {
    if (!activeBatchId.value || !editingItem.value) {
        return;
    }

    editSubmitting.value = true;
    editErrorMessage.value = null;
    editErrors.value = {};

    try {
        await sendJson<BatchItem>(
            documentGeneratorRoutes.batches.items.update.url({
                batch: activeBatchId.value,
                item: editingItem.value.id,
            }),
            'PUT',
            {
                row_data: editForm,
            },
        );

        await Promise.all([loadProgress(), loadBatchItems(itemsData.value.current_page), loadHistory(1)]);
        startPolling();
        closeEditDialog();
    } catch (error) {
        if (error instanceof Error && 'validationErrors' in error) {
            editErrors.value = (error as Error & { validationErrors?: Record<string, string[]> }).validationErrors ?? {};
        }
        editErrorMessage.value = error instanceof Error ? error.message : 'Unable to update row.';
    } finally {
        editSubmitting.value = false;
    }
};

const itemColumns = computed<ColumnDef<BatchItem>[]>(() => [
    {
        id: 'row_number',
        accessorKey: 'row_number',
        header: 'Row',
        enableSorting: true,
    },
    {
        id: 'company',
        accessorKey: 'company',
        header: 'Company',
        enableSorting: false,
        cell: ({ row }) => row.original.company || '-',
    },
    {
        id: 'status',
        accessorKey: 'status',
        header: 'Status',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                Badge,
                {
                    variant: statusBadgeVariant(row.original.status),
                },
                () => row.original.status,
            ),
    },
    {
        id: 'error_message',
        accessorKey: 'error_message',
        header: 'Error',
        enableSorting: false,
        cell: ({ row }) => row.original.error_message ?? '-',
    },
    {
        id: 'actions',
        header: 'Actions',
        enableSorting: false,
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-2' }, [
                h(
                    Button,
                    {
                        variant: 'outline',
                        size: 'sm',
                        disabled: !canEditItem(row.original),
                        onClick: () => openEditDialog(row.original),
                    },
                    () => 'Edit',
                ),
                row.original.docx_available
                    ? h(
                        'a',
                        {
                            href: documentGeneratorRoutes.batches.items.download.url({
                                batch: activeBatchId.value ?? 0,
                                item: row.original.id,
                                type: 'docx',
                            }),
                            class: 'text-primary text-sm underline',
                        },
                        'DOCX',
                    )
                    : h('span', { class: 'text-muted-foreground text-sm' }, 'DOCX'),

                row.original.pdf_available
                    ? h(
                          'a',
                          {
                              href: documentGeneratorRoutes.batches.items.download.url({
                                  batch: activeBatchId.value ?? 0,
                                  item: row.original.id,
                                  type: 'pdf',
                              }),
                              class: 'text-primary text-sm underline',
                              target: '_blank',
                              rel: 'noopener noreferrer',
                          },
                          'Preview PDF',
                      )
                    : h('span', { class: 'text-muted-foreground text-sm' }, 'PDF'),
            ]),
    },
]);

const historyColumns = computed<ColumnDef<HistoryBatch>[]>(() => [
    {
        id: 'id',
        accessorKey: 'id',
        header: 'Batch ID',
        enableSorting: false,
    },
    {
        id: 'source_excel_name',
        accessorKey: 'source_excel_name',
        header: 'Excel',
        enableSorting: false,
    },
    {
        id: 'template_name',
        accessorKey: 'template_name',
        header: 'Template',
        enableSorting: false,
    },
    {
        id: 'generated',
        header: 'Generated',
        enableSorting: false,
        cell: ({ row }) =>
            h(
                Badge,
                {
                    variant: row.original.success_items > 0 ? 'default' : 'secondary',
                },
                () => `${row.original.success_items}/${row.original.total_items}`,
            ),
    },
    {
        id: 'summary',
        header: 'Processed',
        enableSorting: false,
        cell: ({ row }) => `${row.original.processed_items}/${row.original.total_items}`,
    },
    {
        id: 'action',
        header: 'Action',
        enableSorting: false,
        cell: ({ row }) =>
            h(
                Button,
                {
                    variant: 'outline',
                    size: 'sm',
                    onClick: async () => {
                        activeBatchId.value = row.original.id;
                        await Promise.all([loadProgress(), loadBatchItems(1)]);
                        if (progress.value && !['completed', 'failed'].includes(progress.value.status)) {
                            startPolling();
                        } else {
                            stopPolling();
                        }
                    },
                },
                () => 'Open',
            ),
    },
]);

onBeforeUnmount(() => {
    stopPolling();
    if (companySearchDebounce) {
        clearTimeout(companySearchDebounce);
    }
});
</script>

<template>

    <Head title="Document Generator" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <Card>
                <CardHeader>
                    <CardTitle>Bulk Document Generator</CardTitle>
                    <CardDescription>
                        Upload one Excel source and one DOCX template. Every non-header row will generate one DOCX and
                        one PDF.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="grid gap-4 md:grid-cols-3" @submit.prevent="postBatch">
                        <div class="grid gap-2">
                            <Label for="excel">Excel File</Label>
                            <Input id="excel" type="file" accept=".xls,.xlsx" @change="onExcelFileChange" />
                            <p v-if="createErrors.excel_file" class="text-sm text-destructive">
                                {{ createErrors.excel_file[0] }}
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="template">DOCX Template</Label>
                            <Input id="template" type="file" accept=".docx" @change="onTemplateFileChange" />
                            <p v-if="createErrors.template_file" class="text-sm text-destructive">
                                {{ createErrors.template_file[0] }}
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="sheet-index">Sheet Index</Label>
                            <Input id="sheet-index" v-model="sheetIndex" type="number" min="0" />
                            <Button type="submit" :disabled="creatingBatch">
                                <Spinner v-if="creatingBatch" class="size-4" />
                                Start Batch
                            </Button>
                        </div>
                    </form>
                    <p v-if="createErrorMessage" class="mt-3 text-sm text-destructive">
                        {{ createErrorMessage }}
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        Batch Progress
                        <Spinner v-if="pollingActive" class="size-4" />
                    </CardTitle>
                    <CardDescription>
                        {{ progressText }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="h-3 w-full overflow-hidden rounded-full bg-muted">
                        <div class="h-full bg-primary transition-all"
                            :style="{ width: `${progress?.progress_percent ?? 0}%` }" />
                    </div>
                    <div v-if="progress" class="mt-3 grid gap-2 text-sm md:grid-cols-4">
                        <p>Status: <strong>{{ progress.status }}</strong></p>
                        <p>Total: <strong>{{ progress.total_items }}</strong></p>
                        <p>Success: <strong>{{ progress.success_items }}</strong></p>
                        <p>Failed: <strong>{{ progress.failed_items }}</strong></p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Batch Items</CardTitle>
                    <CardDescription>Per-row output status, editing, and downloads for the selected batch.</CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-[220px_minmax(0,320px)]">
                        <div class="max-w-[220px]">
                            <Label class="mb-2 block">Filter by status</Label>
                            <Select :model-value="itemStatusFilter"
                                @update:model-value="(value) => onItemStatusChange(String(value))">
                                <SelectTrigger>
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    <SelectItem value="queued">Queued</SelectItem>
                                    <SelectItem value="processing">Processing</SelectItem>
                                    <SelectItem value="docx_done">Docx Done</SelectItem>
                                    <SelectItem value="pdf_done">Pdf Done</SelectItem>
                                    <SelectItem value="failed">Failed</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="max-w-[320px]">
                            <Label for="company-search" class="mb-2 block">Search company</Label>
                            <Input id="company-search" :model-value="companySearch"
                                placeholder="Type company name..."
                                @input="onCompanySearchInput" />
                        </div>
                    </div>

                    <DataTable :columns="itemColumns" :data="itemsData.data" :meta="itemsData" :loading="itemsLoading"
                        :sort-by="itemsSortBy" :sort-direction="itemsSortDirection" empty-message="No batch items yet."
                        @page-change="loadBatchItems"
                        @per-page-change="async (perPage) => { itemsData.per_page = perPage; await loadBatchItems(1); }"
                        @sort-change="async (column, direction) => { itemsSortBy = column; itemsSortDirection = direction; await loadBatchItems(1); }" />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Batch History</CardTitle>
                    <CardDescription>Paginated backend history of generated batches.</CardDescription>
                </CardHeader>
                <CardContent>
                    <DataTable :columns="historyColumns" :data="historyData.data" :meta="historyData"
                        :loading="historyLoading" :sort-by="historySortBy" :sort-direction="historySortDirection"
                        empty-message="No history available." @page-change="loadHistory"
                        @per-page-change="async (perPage) => { historyData.per_page = perPage; await loadHistory(1); }"
                        @sort-change="async (column, direction) => { historySortBy = column; historySortDirection = direction; await loadHistory(1); }" />
                </CardContent>
            </Card>

            <Dialog :open="editDialogOpen" @update:open="(open) => { if (!open) closeEditDialog(); }">
                <DialogContent class="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Edit Row {{ editingItem?.row_number ?? '-' }}</DialogTitle>
                        <DialogDescription>
                            Update the row data and regenerate documents. Old outputs will be deleted first.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid max-h-[60vh] gap-4 overflow-y-auto py-2">
                        <div v-for="[key] in editFormEntries" :key="key" class="grid gap-2">
                            <Label :for="`edit-${key}`">{{ key }}</Label>
                            <Input :id="`edit-${key}`" v-model="editForm[key]" type="text" />
                            <p v-if="editErrors[`row_data.${key}`]" class="text-sm text-destructive">
                                {{ editErrors[`row_data.${key}`][0] }}
                            </p>
                        </div>
                    </div>

                    <p v-if="editErrorMessage" class="text-sm text-destructive">
                        {{ editErrorMessage }}
                    </p>

                    <DialogFooter>
                        <Button variant="outline" @click="closeEditDialog">Cancel</Button>
                        <Button :disabled="editSubmitting" @click="saveEditedItem">
                            <Spinner v-if="editSubmitting" class="size-4" />
                            Save and Regenerate
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
