<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useSessionStore } from '@/stores/session';
import { apiErrorMessage } from '@/api/http';

const session = useSessionStore();
const router = useRouter();
const route = useRoute();

const email = ref('admin@soahtc.test');
const password = ref('');
const loading = ref(false);
const error = ref<string | null>(null);

async function submit(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        await session.login(email.value, password.value);
        const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/schools';
        await router.push(redirect);
    } catch (e) {
        error.value = apiErrorMessage(e, 'Prijava nije uspela.');
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-sm">
        <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h1 class="text-lg font-semibold">Prijava</h1>
            <p class="mt-1 text-sm text-gray-500">Admin / coordinator pristup</p>

            <form class="mt-5 space-y-4" @submit.prevent="submit">
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="email">Email</label>
                    <input
                        id="email"
                        v-model="email"
                        type="email"
                        autocomplete="username"
                        required
                        class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="password">Lozinka</label>
                    <input
                        id="password"
                        v-model="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    />
                </div>

                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                <button
                    type="submit"
                    :disabled="loading"
                    class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                >
                    {{ loading ? 'Prijavljivanje…' : 'Prijavi se' }}
                </button>
            </form>
        </div>
    </div>
</template>
