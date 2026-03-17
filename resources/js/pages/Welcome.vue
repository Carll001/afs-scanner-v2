<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard, login, register } from '@/routes';
import { Button } from '@/components/ui/button'; // Reusing your existing components
import { Card, CardContent } from '@/components/ui/card';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);
</script>

<template>
    <Head title="Welcome to DocGen" />
    
    <div class="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC] font-sans">
        <nav class="mx-auto flex max-w-7xl items-center justify-between p-6 lg:px-8">
            <div class="flex items-center gap-2">
                <div class="h-8 w-8 rounded-lg bg-gradient-to-br from-slate-950 to-slate-800 shadow-lg"></div>
                <span class="text-xl font-bold tracking-tight">DocGen</span>
            </div>
            
            <div class="flex items-center gap-4">
                <Link v-if="$page.props.auth.user" :href="dashboard()" class="text-sm font-medium hover:opacity-70">
                    Go to Dashboard
                </Link>
                <template v-else>
                    <Link :href="login()" class="text-sm font-medium hover:opacity-70">Log in</Link>
                    <Button v-if="canRegister" as-child class="rounded-full bg-slate-950 px-6 dark:bg-slate-50 dark:text-slate-950">
                        <Link :href="register()">Get Started</Link>
                    </Button>
                </template>
            </div>
        </nav>

        <header class="relative overflow-hidden px-6 pt-16 pb-24 text-center lg:px-8 lg:pt-32">
            <div class="mx-auto max-w-3xl">
                <div class="mb-8 inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium dark:border-slate-800 dark:bg-slate-900">
                    <span class="mr-2 text-blue-500">●</span> 
                    Now supporting complex Year-Rule mappings
                </div>
                <h1 class="text-5xl font-bold tracking-tight sm:text-7xl">
                    Automate your <span class="bg-gradient-to-r from-slate-900 to-slate-500 bg-clip-text text-transparent dark:from-slate-100 dark:to-slate-500">Document Workflow</span>
                </h1>
                <p class="mt-6 text-lg leading-8 text-slate-600 dark:text-slate-400">
                    Transform Excel data into professional documents in seconds. 
                    Manage templates, track batch processing, and scale your output with ease.
                </p>
                <div class="mt-10 flex items-center justify-center gap-x-6">
                    <Button size="lg" as-child class="h-12 px-8 text-md shadow-xl">
                        <Link :href="register()">Start Generating</Link>
                    </Button>
                    <a href="#preview" class="text-sm font-semibold leading-6 opacity-60 hover:opacity-100">
                        See how it works <span aria-hidden="true">→</span>
                    </a>
                </div>
            </div>
        </header>

        <section class="mx-auto max-w-7xl px-6 lg:px-8 pb-24">
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <Card class="border-slate-200/60 bg-white/50 dark:border-slate-800 dark:bg-slate-900/50">
                    <CardContent class="p-8">
                        <div class="mb-4 text-2xl">📊</div>
                        <h3 class="font-bold">Batch Processing</h3>
                        <p class="mt-2 text-sm text-slate-500">Handle thousands of items in a single run. Real-time status tracking for success and failure items.</p>
                    </CardContent>
                </Card>
                
                <Card class="border-slate-200/60 bg-white/50 dark:border-slate-800 dark:bg-slate-900/50">
                    <CardContent class="p-8">
                        <div class="mb-4 text-2xl">🗺️</div>
                        <h3 class="font-bold">Template Mapping</h3>
                        <p class="mt-2 text-sm text-slate-500">Dynamic year rules and default configurations ensure your data always finds the right home.</p>
                    </CardContent>
                </Card>

                <Card class="border-slate-200/60 bg-white/50 dark:border-slate-800 dark:bg-slate-900/50">
                    <CardContent class="p-8">
                        <div class="mb-4 text-2xl">📁</div>
                        <h3 class="font-bold">File Management</h3>
                        <p class="mt-2 text-sm text-slate-500">Complete history of generated files with instant downloads and workspace-wide search.</p>
                    </CardContent>
                </Card>
            </div>

            <div id="preview" class="mt-16 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 p-2 dark:border-slate-800 dark:bg-slate-900">
                <div class="rounded-xl bg-gradient-to-br from-slate-950 via-slate-900 to-slate-800 p-8 shadow-2xl">
                    <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                        <div>
                            <div class="inline-flex rounded-full bg-slate-800 px-3 py-1 text-[10px] text-slate-400 uppercase tracking-widest">Preview Mode</div>
                            <h2 class="mt-2 text-2xl font-bold text-white">Document Operations Dashboard</h2>
                        </div>
                        <div class="flex gap-2">
                             <div class="h-8 w-24 rounded bg-slate-700/50 animate-pulse"></div>
                             <div class="h-8 w-24 rounded bg-white/10 animate-pulse"></div>
                        </div>
                    </div>
                    <div class="mt-8 grid grid-cols-4 gap-4">
                        <div v-for="i in 4" :key="i" class="h-20 rounded-lg bg-slate-800/50 border border-slate-700/50"></div>
                    </div>
                </div>
            </div>
        </section>

        <footer class="border-t border-slate-100 py-12 text-center text-sm text-slate-400 dark:border-slate-900">
            &copy; 2026 DocGen Automation. All rights reserved.
        </footer>
    </div>
</template>