<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthSplitLayout from '@/layouts/auth/AuthSplitLayout.vue';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();
</script>

<template>
    <AuthSplitLayout
        title="Log in to your account"
        description="Use your registered credentials to continue to the AFS Scanner workspace."
    >
        <Head title="Log in" />

        <div
            v-if="status"
            class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-center text-sm font-medium text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300"
        >
            {{ status }}
        </div>

        <Form
            v-bind="store.form()"
            :reset-on-success="['password']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div
                class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600 dark:border-white/10 dark:bg-slate-950 dark:text-slate-300"
            >
                Sign in to manage document batches, template mapping, and
                generated file review.
            </div>

            <div class="grid gap-5">
                <div class="grid gap-2">
                    <Label
                        for="email"
                        class="text-slate-700 dark:text-slate-200"
                        >Email address</Label
                    >
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="email@example.com"
                        class="h-11 rounded-xl border-slate-200 bg-slate-50 px-3 shadow-none focus-visible:border-slate-400 focus-visible:bg-white focus-visible:ring-slate-300/60 dark:border-white/10 dark:bg-slate-950 dark:focus-visible:border-slate-500 dark:focus-visible:ring-slate-700/60"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label
                            for="password"
                            class="text-slate-700 dark:text-slate-200"
                            >Password</Label
                        >
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-sm font-medium text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
                            :tabindex="5"
                        >
                            Forgot password?
                        </TextLink>
                    </div>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        placeholder="Password"
                        class="h-11 rounded-xl border-slate-200 bg-slate-50 px-3 shadow-none focus-visible:border-slate-400 focus-visible:bg-white focus-visible:ring-slate-300/60 dark:border-white/10 dark:bg-slate-950 dark:focus-visible:border-slate-500 dark:focus-visible:ring-slate-700/60"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <Label
                        for="remember"
                        class="flex items-center space-x-3 text-sm text-slate-600 dark:text-slate-300"
                    >
                        <Checkbox id="remember" name="remember" :tabindex="3" />
                        <span>Remember me</span>
                    </Label>
                </div>

                <Button
                    type="submit"
                    class="mt-2 h-11 w-full rounded-xl bg-slate-950 text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200"
                    :tabindex="4"
                    :disabled="processing"
                    data-test="login-button"
                >
                    <Spinner v-if="processing" />
                    Log in
                </Button>
            </div>

            <p
                class="text-center text-xs leading-6 text-slate-500 dark:text-slate-400"
            >
                Protected access for authorized accounts only.
            </p>

            <div
                class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-center text-sm text-slate-600 dark:border-white/10 dark:bg-slate-950 dark:text-slate-300"
                v-if="canRegister"
            >
                Don't have an account?
                <TextLink
                    :href="register()"
                    :tabindex="5"
                    class="font-semibold text-slate-900 hover:text-slate-700 dark:text-white dark:hover:text-slate-200"
                >
                    Sign up
                </TextLink>
            </div>
        </Form>
    </AuthSplitLayout>
</template>
