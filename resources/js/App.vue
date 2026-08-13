<script setup lang="ts">
import { RouterLink, RouterView, useRouter } from 'vue-router';
import { useSessionStore } from '@/stores/session';

const session = useSessionStore();
const router = useRouter();

async function logout(): Promise<void> {
    await session.logout();
    await router.push({ name: 'login' });
}
</script>

<template>
    <div class="min-h-screen bg-gray-50 text-gray-900">
        <header class="border-b border-gray-200 bg-white">
            <nav class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                <div class="flex items-center gap-6">
                    <RouterLink to="/" class="text-lg font-semibold tracking-tight">SOA HTC</RouterLink>
                    <RouterLink to="/" class="text-sm text-gray-600 hover:text-gray-900">Home</RouterLink>
                    <RouterLink
                        v-if="session.can('schools.view')"
                        to="/schools"
                        class="text-sm text-gray-600 hover:text-gray-900"
                    >
                        Škole
                    </RouterLink>
                    <RouterLink
                        v-if="session.can('users.manage')"
                        to="/users"
                        class="text-sm text-gray-600 hover:text-gray-900"
                    >
                        Korisnici
                    </RouterLink>
                </div>

                <div class="flex items-center gap-4 text-sm">
                    <template v-if="session.isAuthenticated">
                        <span class="text-gray-500">{{ session.user?.email }}</span>
                        <button class="text-gray-600 hover:text-gray-900" @click="logout">Odjava</button>
                    </template>
                    <RouterLink v-else to="/login" class="text-gray-600 hover:text-gray-900">Prijava</RouterLink>
                </div>
            </nav>
        </header>

        <main class="mx-auto max-w-5xl px-6 py-10">
            <RouterView />
        </main>
    </div>
</template>
