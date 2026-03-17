<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { FileCog, FolderKanban, ShieldCheck, Workflow } from 'lucide-vue-next';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

const page = usePage();
const name = page.props.name;
const highlights = [
    {
        title: 'Batch workflows',
        description: 'Process spreadsheet-driven documents from one workspace.',
        icon: Workflow,
    },
    {
        title: 'Template control',
        description: 'Keep mappings and document rules consistent across runs.',
        icon: FileCog,
    },
    {
        title: 'Output review',
        description:
            'Open generated files and revisit history without friction.',
        icon: FolderKanban,
    },
];

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="h-svh overflow-hidden bg-[linear-gradient(180deg,_#f8fafc_0%,_#e2e8f0_100%)] text-slate-950 dark:bg-[linear-gradient(180deg,_#020617_0%,_#111827_54%,_#020617_100%)] dark:text-white"
    >
        <div
            class="mx-auto grid h-full w-full max-w-7xl lg:grid-cols-[minmax(0,1.05fr)_minmax(420px,520px)]"
        >
            <div
                class="hidden h-full px-8 py-6 lg:flex lg:items-center lg:justify-center xl:px-12"
            >
                <div
                    class="relative h-full max-h-[calc(100svh-3rem)] w-full max-w-2xl overflow-hidden rounded-[2rem] border border-white/70 bg-gradient-to-br from-slate-950 via-slate-900 to-slate-800 px-10 py-10 text-white shadow-2xl shadow-slate-950/20 xl:px-12 dark:border-white/10"
                >
                    <div
                        class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(255,255,255,0.06),transparent_24%),radial-gradient(circle_at_bottom_right,rgba(148,163,184,0.12),transparent_28%)]"
                    />
                    <div
                        class="absolute inset-0 [background-image:linear-gradient(rgba(255,255,255,0.06)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.06)_1px,transparent_1px)] [background-size:32px_32px] opacity-40"
                    />

                    <div
                        class="relative flex h-full flex-col justify-between gap-8"
                    >
                        <div class="space-y-8">
                            <Link
                                :href="home()"
                                class="inline-flex items-center gap-3 text-sm font-semibold tracking-[0.2em] text-slate-200 uppercase"
                            >
                                <div
                                    class="flex h-12 w-12 items-center justify-center rounded-2xl border border-slate-800/10 bg-white text-slate-950"
                                >
                                    <AppLogoIcon class="size-6 fill-current" />
                                </div>
                                <span>{{ name }}</span>
                            </Link>

                            <div class="max-w-xl space-y-4">
                                <p
                                    class="text-sm font-medium tracking-[0.24em] text-slate-300 uppercase"
                                >
                                    Authorized Access
                                </p>
                                <h2
                                    class="text-3xl leading-tight font-semibold tracking-tight text-balance xl:text-4xl"
                                >
                                    A cleaner sign-in experience for daily
                                    document operations.
                                </h2>
                                <p
                                    class="max-w-lg text-sm leading-7 text-slate-300 xl:text-base"
                                >
                                    Access your workspace to manage template
                                    setup, run document batches, and review
                                    generated outputs in one place.
                                </p>
                            </div>

                            <div class="grid gap-3">
                                <div
                                    v-for="item in highlights"
                                    :key="item.title"
                                    class="grid grid-cols-[auto_1fr] gap-4 rounded-2xl border border-white/10 bg-white/5 px-5 py-3.5"
                                >
                                    <div
                                        class="flex h-11 w-11 items-center justify-center rounded-xl bg-white/10 text-slate-100"
                                    >
                                        <component
                                            :is="item.icon"
                                            class="size-5"
                                        />
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold">
                                            {{ item.title }}
                                        </p>
                                        <p
                                            class="mt-1 text-sm leading-6 text-slate-300"
                                        >
                                            {{ item.description }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            class="rounded-2xl border border-white/10 bg-white/5 p-4"
                        >
                            <div class="flex items-start gap-3">
                                <div
                                    class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-400/10 text-emerald-300"
                                >
                                    <ShieldCheck class="size-5" />
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-white">
                                        Secure workspace entry
                                    </p>
                                    <p
                                        class="mt-1 text-sm leading-6 text-slate-300"
                                    >
                                        Only registered users can access
                                        templates, batch actions, and generated
                                        file history.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="flex h-full items-center justify-center px-6 py-6 sm:px-10"
            >
                <div class="w-full max-w-md">
                    <div class="mb-5 flex flex-col gap-4 text-center lg:hidden">
                        <Link
                            :href="home()"
                            class="inline-flex items-center justify-center gap-3 text-sm font-semibold tracking-[0.2em] text-slate-500 uppercase lg:hidden dark:text-slate-400"
                        >
                            <div
                                class="flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-950 text-white dark:bg-white dark:text-slate-950"
                            >
                                <AppLogoIcon class="size-6 fill-current" />
                            </div>
                            <span>{{ name }}</span>
                        </Link>
                    </div>

                    <div
                        class="rounded-[2rem] border border-slate-200/80 bg-white/90 px-6 py-7 shadow-2xl shadow-slate-300/20 backdrop-blur sm:px-8 dark:border-white/10 dark:bg-slate-900/85 dark:shadow-black/20"
                    >
                        <div class="mb-6 space-y-2.5">
                            <p
                                class="text-xs font-semibold tracking-[0.24em] text-slate-500 uppercase dark:text-slate-400"
                            >
                                Access Portal
                            </p>
                            <h1
                                class="text-2xl font-semibold tracking-tight"
                                v-if="title"
                            >
                                {{ title }}
                            </h1>
                            <p
                                class="text-sm leading-7 text-slate-500 dark:text-slate-400"
                                v-if="description"
                            >
                                {{ description }}
                            </p>
                        </div>
                        <slot />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
