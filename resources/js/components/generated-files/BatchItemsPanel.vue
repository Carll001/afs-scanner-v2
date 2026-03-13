<script setup lang="ts">
import type { ColumnDef } from '@tanstack/vue-table';
import { computed, h, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import documentGeneratorRoutes from '@/routes/document-generator';

type SortDirection = 'asc' | 'desc';

type BatchSummary = {
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

type PaginatedResponse<T> = {
    current_page: number;
    data: T[];
    last_page: number;
    per_page: number;
    total: number;
};

const props = defineProps<{
    batch: BatchSummary;
}>();

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
const pollingActive = ref(false);

const editDialogOpen = ref(false);
const editSubmitting = ref(false);
const editErrorMessage = ref<string | null>(null);
const editErrors = ref<Record<string, string[]>>({});
const editingItem = ref<BatchItem | null>(null);
const editForm = reactive<Record<string, string>>({});

let companySearchDebounce: ReturnType<typeof setTimeout> | null = null;
let pollInterval: ReturnType<typeof setInterval> | null = null;

const csrfToken = () => {
    const xsrfCookie = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='));

    if (!xsrfCookie) {
        return '';
    }

    return decodeURIComponent(xsrfCookie.split('=')[1] ?? '');
};

const getApi = async <T,>(url: string): Promise<T> => {
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

const sendJson = async <T,>(
    url: string,
    method: 'PUT',
    payload: unknown,
): Promise<T> => {
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
        const validationError = new Error(
            errorPayload.message ?? 'Validation failed.',
        );
        Object.assign(validationError, {
            validationErrors: errorPayload.errors ?? {},
        });
        throw validationError;
    }

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
};

const loadBatchItems = async (page = itemsData.value.current_page) => {
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
                { batch: props.batch.id },
                {
                    query,
                },
            ),
        );

        syncPolling();
    } finally {
        itemsLoading.value = false;
    }
};

const statusBadgeVariant = (
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' => {
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

const canEditItem = (item: BatchItem) =>
    !['queued', 'processing'].includes(item.status);

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

const stopPolling = () => {
    pollingActive.value = false;

    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
};

const syncPolling = () => {
    const shouldPoll = itemsData.value.data.some((item) =>
        ['queued', 'processing'].includes(item.status),
    );

    if (!shouldPoll) {
        stopPolling();
        return;
    }

    if (pollInterval) {
        pollingActive.value = true;
        return;
    }

    pollingActive.value = true;
    pollInterval = setInterval(() => {
        void loadBatchItems();
    }, 2000);
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

const editFormEntries = computed(() => Object.entries(editForm));

const saveEditedItem = async () => {
    if (!editingItem.value) {
        return;
    }

    editSubmitting.value = true;
    editErrorMessage.value = null;
    editErrors.value = {};

    try {
        await sendJson<BatchItem>(
            documentGeneratorRoutes.batches.items.update.url({
                batch: props.batch.id,
                item: editingItem.value.id,
            }),
            'PUT',
            {
                row_data: editForm,
            },
        );

        await loadBatchItems(itemsData.value.current_page);
        syncPolling();
        closeEditDialog();
    } catch (error) {
        if (error instanceof Error && 'validationErrors' in error) {
            editErrors.value =
                (
                    error as Error & {
                        validationErrors?: Record<string, string[]>;
                    }
                ).validationErrors ?? {};
        }

        editErrorMessage.value =
            error instanceof Error ? error.message : 'Unable to update row.';
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
                              href: documentGeneratorRoutes.batches.items.download.url(
                                  {
                                      batch: props.batch.id,
                                      item: row.original.id,
                                      type: 'docx',
                                  },
                              ),
                              class: 'text-primary text-sm underline',
                          },
                          'DOCX',
                      )
                    : h(
                          'span',
                          { class: 'text-muted-foreground text-sm' },
                          'DOCX',
                      ),
                row.original.pdf_available
                    ? h(
                          'a',
                          {
                              href: documentGeneratorRoutes.batches.items.download.url(
                                  {
                                      batch: props.batch.id,
                                      item: row.original.id,
                                      type: 'pdf',
                                  },
                              ),
                              class: 'text-primary text-sm underline',
                              target: '_blank',
                              rel: 'noopener noreferrer',
                          },
                          'Preview PDF',
                      )
                    : h(
                          'span',
                          { class: 'text-muted-foreground text-sm' },
                          'PDF',
                      ),
            ]),
    },
]);

onMounted(() => {
    void loadBatchItems(1);
});

onBeforeUnmount(() => {
    stopPolling();

    if (companySearchDebounce) {
        clearTimeout(companySearchDebounce);
    }
});
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="flex items-center gap-2">
                Batch #{{ batch.id }} Files
                <Spinner v-if="pollingActive" class="size-4" />
            </CardTitle>
            <CardDescription>
                {{ batch.source_excel_name }} using {{ batch.template_name }}
            </CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
            <div class="grid gap-4 md:grid-cols-[220px_minmax(0,320px)]">
                <div class="max-w-[220px]">
                    <Label class="mb-2 block">Filter by status</Label>
                    <Select
                        :model-value="itemStatusFilter"
                        @update:model-value="
                            (value) => onItemStatusChange(String(value))
                        "
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="All statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All</SelectItem>
                            <SelectItem value="queued">Queued</SelectItem>
                            <SelectItem value="processing"
                                >Processing</SelectItem
                            >
                            <SelectItem value="docx_done">Docx Done</SelectItem>
                            <SelectItem value="pdf_done">Pdf Done</SelectItem>
                            <SelectItem value="failed">Failed</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="max-w-[320px]">
                    <Label for="generated-company-search" class="mb-2 block"
                        >Search company</Label
                    >
                    <Input
                        id="generated-company-search"
                        :model-value="companySearch"
                        placeholder="Type company name..."
                        @input="onCompanySearchInput"
                    />
                </div>
            </div>

            <DataTable
                :columns="itemColumns"
                :data="itemsData.data"
                :meta="itemsData"
                :loading="itemsLoading"
                :sort-by="itemsSortBy"
                :sort-direction="itemsSortDirection"
                empty-message="No batch items yet."
                @page-change="loadBatchItems"
                @per-page-change="
                    async (perPage) => {
                        itemsData.per_page = perPage;
                        await loadBatchItems(1);
                    }
                "
                @sort-change="
                    async (column, direction) => {
                        itemsSortBy = column;
                        itemsSortDirection = direction;
                        await loadBatchItems(1);
                    }
                "
            />
        </CardContent>
    </Card>

    <Dialog
        :open="editDialogOpen"
        @update:open="
            (open) => {
                if (!open) closeEditDialog();
            }
        "
    >
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle
                    >Edit Row {{ editingItem?.row_number ?? '-' }}</DialogTitle
                >
                <DialogDescription>
                    Update the row data and regenerate documents. Old outputs
                    will be deleted first.
                </DialogDescription>
            </DialogHeader>

            <div class="grid max-h-[60vh] gap-4 overflow-y-auto py-2">
                <div
                    v-for="[key] in editFormEntries"
                    :key="key"
                    class="grid gap-2"
                >
                    <Label :for="`edit-${key}`">{{ key }}</Label>
                    <Input
                        :id="`edit-${key}`"
                        v-model="editForm[key]"
                        type="text"
                    />
                    <p
                        v-if="editErrors[`row_data.${key}`]"
                        class="text-sm text-destructive"
                    >
                        {{ editErrors[`row_data.${key}`][0] }}
                    </p>
                </div>
            </div>

            <p v-if="editErrorMessage" class="text-sm text-destructive">
                {{ editErrorMessage }}
            </p>

            <DialogFooter>
                <Button variant="outline" @click="closeEditDialog"
                    >Cancel</Button
                >
                <Button :disabled="editSubmitting" @click="saveEditedItem">
                    <Spinner v-if="editSubmitting" class="size-4" />
                    Save and Regenerate
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
