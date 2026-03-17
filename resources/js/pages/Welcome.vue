<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, CheckCircle2, Files, FolderKanban, Sparkles } from 'lucide-vue-next';
import { dashboard, login, register } from '@/routes';
import documentGeneratorRoutes from '@/routes/document-generator';
import generatedFilesRoutes from '@/routes/generated-files';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);

const stats = [
    {
        label: 'Single upload',
        value: 'Excel to batch',
    },
    {
        label: 'Reusable setup',
        value: 'Template mapping',
    },
    {
        label: 'Trackable output',
        value: 'Generated files',
    },
];

const featureCards = [
    {
        title: 'Batch document generation',
        description:
            'Start one batch from an Excel source and let the app process row-by-row outputs with clear status tracking.',
        href: documentGeneratorRoutes.index().url,
        icon: Files,
    },
    {
        title: 'Template mapping control',
        description:
            'Manage default templates and year-based template rules so each output follows the right document setup.',
        href: documentGeneratorRoutes.templateMapping().url,
        icon: Sparkles,
    },
    {
        title: 'Generated files review',
        description:
            'Open finished batches, review outputs, and revisit processed files without digging through folders manually.',
        href: generatedFilesRoutes.index().url,
        icon: FolderKanban,
    },
];

const workflow = [
    {
        step: '01',
        title: 'Prepare your source files',
        description:
            'Upload the Excel file, choose the default template, and set the sheet index for the batch.',
    },
    {
        step: '02',
        title: 'Map templates with confidence',
        description:
            'Configure mappings and fallback templates once so repeated runs stay consistent and easier to maintain.',
    },
    {
        step: '03',
        title: 'Review outputs and progress',
        description:
            'Monitor processing, inspect row-level results, and download DOCX or PDF outputs when they are ready.',
    },
];
</script>

<template>
    <Head title="Welcome" />

    <div
        class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(244,114,36,0.2),_transparent_34%),radial-gradient(circle_at_80%_20%,_rgba(14,165,233,0.16),_transparent_28%),linear-gradient(180deg,_#fffdf8_0%,_#fff7ed_42%,_#ffffff_100%)] text-slate-950 dark:bg-[radial-gradient(circle_at_top_left,_rgba(249,115,22,0.16),_transparent_28%),radial-gradient(circle_at_80%_20%,_rgba(14,165,233,0.14),_transparent_24%),linear-gradient(180deg,_#020617_0%,_#111827_54%,_#020617_100%)] dark:text-white"
    >
        <div class="mx-auto flex min-h-screen w-full max-w-7xl flex-col px-6 py-6 lg:px-10 lg:py-8">
            <header class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-11 w-11 items-center justify-center rounded-2xl border border-orange-500/20 bg-orange-500 text-sm font-semibold text-white shadow-lg shadow-orange-500/20"
                    >
                        AFS
                    </div>
                    <div>
                        <p class="text-sm font-semibold tracking-[0.24em] text-orange-600 uppercase dark:text-orange-300">
                            AFS Scanner
                        </p>
                        <p class="text-sm text-slate-600 dark:text-slate-300">
                            Batch-ready document workflow
                        </p>
                    </div>
                </div>

                <nav class="flex items-center gap-3">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="dashboard()"
                        class="inline-flex items-center rounded-full border border-slate-300/80 bg-white/70 px-4 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-400 hover:bg-white dark:border-white/15 dark:bg-white/5 dark:text-slate-100 dark:hover:bg-white/10"
                    >
                        Dashboard
                    </Link>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="inline-flex items-center rounded-full border border-transparent px-4 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-white/60 dark:text-slate-100 dark:hover:border-white/10 dark:hover:bg-white/5"
                        >
                            Log in
                        </Link>
                        <Link
                            v-if="canRegister"
                            :href="register()"
                            class="inline-flex items-center rounded-full bg-slate-950 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-100"
                        >
                            Register
                        </Link>
                    </template>
                </nav>
            </header>

            <main class="flex flex-1 flex-col gap-10 py-10 lg:gap-14 lg:py-14">
                <section class="grid items-center gap-8 lg:grid-cols-[1.15fr_0.85fr]">
                    <div class="space-y-8">
                        <div
                            class="inline-flex items-center gap-2 rounded-full border border-orange-500/20 bg-white/70 px-4 py-2 text-sm text-slate-700 shadow-sm backdrop-blur dark:border-orange-400/20 dark:bg-white/5 dark:text-slate-200"
                        >
                            <CheckCircle2 class="h-4 w-4 text-orange-500" />
                            Built for repeatable document generation and review
                        </div>

                        <div class="space-y-5">
                            <h1 class="max-w-3xl text-5xl leading-tight font-semibold tracking-tight text-balance lg:text-7xl">
                                Turn spreadsheet rows into organized document batches.
                            </h1>
                            <p class="max-w-2xl text-lg leading-8 text-slate-600 dark:text-slate-300">
                                A more useful welcome page for this app should lead people straight into the workflow:
                                generate documents, manage template mapping, and review generated outputs without the
                                default starter content.
                            </p>
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row">
                            <Link
                                :href="$page.props.auth.user ? dashboard() : login()"
                                class="inline-flex items-center justify-center gap-2 rounded-full bg-orange-500 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-orange-500/25 transition hover:bg-orange-400"
                            >
                                {{ $page.props.auth.user ? 'Open dashboard' : 'Start with login' }}
                                <ArrowRight class="h-4 w-4" />
                            </Link>
                            <Link
                                :href="$page.props.auth.user ? documentGeneratorRoutes.index().url : login()"
                                class="inline-flex items-center justify-center rounded-full border border-slate-300/80 bg-white/75 px-6 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-white dark:border-white/15 dark:bg-white/5 dark:text-slate-100 dark:hover:bg-white/10"
                            >
                                View document generator
                            </Link>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <div
                                v-for="item in stats"
                                :key="item.label"
                                class="rounded-3xl border border-white/70 bg-white/70 p-5 shadow-sm backdrop-blur dark:border-white/10 dark:bg-white/5"
                            >
                                <p class="text-sm text-slate-500 dark:text-slate-400">
                                    {{ item.label }}
                                </p>
                                <p class="mt-2 text-lg font-semibold text-slate-950 dark:text-white">
                                    {{ item.value }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="relative">
                        <div
                            class="absolute inset-0 rounded-[2rem] bg-gradient-to-br from-orange-500/20 via-sky-400/10 to-transparent blur-3xl"
                        />
                        <div
                            class="relative overflow-hidden rounded-[2rem] border border-white/70 bg-slate-950 p-6 text-white shadow-2xl shadow-slate-950/20 dark:border-white/10"
                        >
                            <div class="flex flex-col items-start gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-sm font-medium text-orange-300">Live workflow snapshot</p>
                                    <h2 class="mt-1 text-2xl font-semibold">Welcome page recommendation</h2>
                                </div>
                                <div
                                    class="inline-flex shrink-0 rounded-full bg-white/10 px-3 py-1 text-xs tracking-[0.24em] uppercase text-slate-200"
                                >
                                    Product-first
                                </div>
                            </div>

                            <div class="mt-6 space-y-4">
                                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-slate-300">Default template ready</span>
                                        <span class="rounded-full bg-emerald-400/15 px-2.5 py-1 text-emerald-300">
                                            Connected
                                        </span>
                                    </div>
                                    <div class="mt-4 h-2 rounded-full bg-white/10">
                                        <div class="h-2 w-[72%] rounded-full bg-gradient-to-r from-orange-400 to-sky-400" />
                                    </div>
                                    <p class="mt-3 text-xs text-slate-400">
                                        Batch progress visibility should be previewed here to reinforce the main use case.
                                    </p>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                        <p class="text-xs tracking-[0.24em] uppercase text-slate-400">Core area</p>
                                        <p class="mt-2 text-lg font-semibold">Template Mapping</p>
                                        <p class="mt-2 text-sm text-slate-300">
                                            Make mappings and fallback rules easy to discover from the first screen.
                                        </p>
                                    </div>
                                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                        <p class="text-xs tracking-[0.24em] uppercase text-slate-400">Core area</p>
                                        <p class="mt-2 text-lg font-semibold">Generated Files</p>
                                        <p class="mt-2 text-sm text-slate-300">
                                            Users should instantly know where to review completed outputs and history.
                                        </p>
                                    </div>
                                </div>

                                <div class="rounded-2xl border border-orange-400/25 bg-orange-400/10 p-4">
                                    <p class="text-sm font-medium text-orange-200">Recommended direction</p>
                                    <p class="mt-2 text-sm leading-6 text-slate-200">
                                        Keep this page as a lightweight product landing page, then let the authenticated
                                        experience do the heavier work inside the dashboard and generator screens.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="grid gap-4 lg:grid-cols-3">
                    <Link
                        v-for="card in featureCards"
                        :key="card.title"
                        :href="$page.props.auth.user ? card.href : login()"
                        class="group rounded-[1.75rem] border border-slate-200/80 bg-white/80 p-6 shadow-sm transition hover:-translate-y-1 hover:border-slate-300 hover:shadow-lg dark:border-white/10 dark:bg-white/5 dark:hover:border-white/20"
                    >
                        <component
                            :is="card.icon"
                            class="h-11 w-11 rounded-2xl bg-orange-100 p-3 text-orange-600 dark:bg-orange-400/15 dark:text-orange-300"
                        />
                        <h3 class="mt-5 text-xl font-semibold text-slate-950 dark:text-white">
                            {{ card.title }}
                        </h3>
                        <p class="mt-3 text-sm leading-7 text-slate-600 dark:text-slate-300">
                            {{ card.description }}
                        </p>
                        <div class="mt-5 inline-flex items-center gap-2 text-sm font-medium text-orange-600 dark:text-orange-300">
                            Explore this section
                            <ArrowRight class="h-4 w-4 transition group-hover:translate-x-1" />
                        </div>
                    </Link>
                </section>

                <section class="rounded-[2rem] border border-slate-200/80 bg-white/80 p-6 shadow-sm dark:border-white/10 dark:bg-white/5 lg:p-8">
                    <div class="mb-6 max-w-2xl space-y-3">
                        <p class="text-sm font-semibold tracking-[0.24em] text-orange-600 uppercase dark:text-orange-300">
                            Simple Workflow
                        </p>
                        <h2 class="text-3xl font-semibold tracking-tight text-balance">
                            From source file to generated output
                        </h2>
                        <p class="text-base leading-8 text-slate-600 dark:text-slate-300">
                            Keep the landing page focused on what users actually do inside the app: prepare files, map
                            templates, and review generated documents.
                        </p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <div
                            v-for="item in workflow"
                            :key="item.step"
                            class="rounded-[1.5rem] border border-slate-200/80 bg-white p-5 dark:border-white/10 dark:bg-slate-950/40"
                        >
                            <p class="text-sm font-semibold tracking-[0.2em] text-orange-500 uppercase">
                                {{ item.step }}
                            </p>
                            <h3 class="mt-3 text-lg font-semibold text-slate-950 dark:text-white">
                                {{ item.title }}
                            </h3>
                            <p class="mt-3 text-sm leading-7 text-slate-600 dark:text-slate-300">
                                {{ item.description }}
                            </p>
                        </div>
                    </div>
                </section>
            </main>
        </div>
    </div>
</template>
