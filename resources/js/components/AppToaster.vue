<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Toaster } from '@/components/ui/sonner';
import { showToast } from '@/lib/toast';
import type { FlashToast } from '@/types/ui';
import 'vue-sonner/style.css';

const page = usePage();
const lastToastId = ref<string | null>(null);
const flashToast = computed(() => page.props.flash?.toast ?? null);

watch(
    flashToast,
    (toastPayload) => {
        if (
            typeof window === 'undefined'
            || !toastPayload
            || toastPayload.id === lastToastId.value
        ) {
            return;
        }

        lastToastId.value = toastPayload.id;
        showToast(toastPayload as FlashToast);
    },
    { immediate: true },
);
</script>

<template>
    <Toaster position='bottom-right' rich-colors />
</template>
