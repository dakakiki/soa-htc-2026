<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { getUser } from '@/api/users';
import { createAssignment, deleteAssignment, type AssignmentPayload } from '@/api/assignments';
import { listRoles } from '@/api/reference';
import { listSchools } from '@/api/schools';
import { apiErrorMessage } from '@/api/http';
import { useConfirmStore } from '@/stores/confirm';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import type { AdminUser, Role, School } from '@/types/models';

const route = useRoute();
const { t } = useI18n();
const confirm = useConfirmStore();
const id = Number(route.params.id);

const user = ref<AdminUser | null>(null);
const roles = ref<Role[]>([]);
const assignSchools = ref<School[]>([]);
const error = ref<string | null>(null);

const assignForm = reactive<{ role_id: number | null; school_ids: number[] }>({ role_id: null, school_ids: [] });
const singleSchool = ref<number | null>(null);
const assignSaving = ref(false);
const assignError = ref<string | null>(null);

const selectedRoleKey = computed(() => roles.value.find((r) => r.id === assignForm.role_id)?.key);
const needsSingleSchool = computed(() => selectedRoleKey.value === 'school_coordinator');
const needsMultiSchool = computed(() => selectedRoleKey.value === 'country_coordinator');

async function loadUser(): Promise<void> {
    const { data } = await getUser(id);
    user.value = data.data;
}

async function submitAssignment(): Promise<void> {
    if (user.value === null || assignForm.role_id === null) {
        assignError.value = t('user.selectRole');
        return;
    }
    const payload: AssignmentPayload = { role_id: assignForm.role_id };
    if (needsSingleSchool.value) {
        payload.school_ids = singleSchool.value === null ? [] : [singleSchool.value];
    } else if (needsMultiSchool.value) {
        payload.school_ids = assignForm.school_ids;
    }

    assignSaving.value = true;
    assignError.value = null;
    try {
        await createAssignment(user.value.id, payload);
        assignForm.role_id = null;
        assignForm.school_ids = [];
        singleSchool.value = null;
        await loadUser();
    } catch (e) {
        assignError.value = apiErrorMessage(e, t('user.assignFailed'));
    } finally {
        assignSaving.value = false;
    }
}

async function removeAssignment(assignmentId: number): Promise<void> {
    if (!(await confirm.ask({ message: t('user.confirmRemove') }))) {
        return;
    }
    try {
        await deleteAssignment(assignmentId);
        await loadUser();
    } catch (e) {
        error.value = apiErrorMessage(e);
    }
}

onMounted(async () => {
    try {
        await loadUser();
        const rolesRes = await listRoles();
        roles.value = rolesRes.data.data;
        if (user.value?.country.id) {
            const { data } = await listSchools({ country_id: user.value.country.id, per_page: 200 });
            assignSchools.value = data.data;
        }
    } catch (e) {
        error.value = apiErrorMessage(e, t('user.notFound'));
    }
});
</script>

<template>
    <section class="space-y-5">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <RouterLink :to="{ name: 'users' }" class="hover:text-gray-900">{{ $t('user.title') }}</RouterLink>
            <span>/</span>
            <span class="text-gray-900">{{ user?.name ?? $t('user.one') }}</span>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

        <div v-if="user" class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <div class="flex items-start justify-between">
                    <h1 class="text-2xl font-semibold tracking-tight">{{ user.name }}</h1>
                    <RouterLink :to="{ name: 'users.edit', params: { id: user.id } }" class="text-sm text-blue-600 hover:underline">
                        {{ $t('common.edit') }}
                    </RouterLink>
                </div>
                <dl class="mt-4 grid grid-cols-3 gap-2 text-sm">
                    <dt class="text-gray-500">{{ $t('user.email') }}</dt>
                    <dd class="col-span-2 text-gray-900">{{ user.email }}</dd>
                    <dt class="text-gray-500">{{ $t('user.status') }}</dt>
                    <dd class="col-span-2"><ToggleSwitch :model-value="user.status === 'active'" disabled :aria-label="$t('user.toggleStatus')" /></dd>
                    <dt class="text-gray-500">{{ $t('user.country') }}</dt>
                    <dd class="col-span-2 text-gray-900">{{ user.country.name ?? $t('common.dash') }}</dd>
                    <dt class="text-gray-500">{{ $t('user.region') }}</dt>
                    <dd class="col-span-2 text-gray-900">{{ user.region?.name ?? $t('common.dash') }}</dd>
                    <dt class="text-gray-500">{{ $t('user.city') }}</dt>
                    <dd class="col-span-2 text-gray-900">{{ user.city || $t('common.dash') }}</dd>
                    <dt class="text-gray-500">{{ $t('user.address') }}</dt>
                    <dd class="col-span-2 text-gray-900">{{ user.address || $t('common.dash') }}</dd>
                    <dt class="text-gray-500">{{ $t('user.phone') }}</dt>
                    <dd class="col-span-2 text-gray-900">{{ user.phone || $t('common.dash') }}</dd>
                    <dt class="text-gray-500">{{ $t('user.roles') }}</dt>
                    <dd class="col-span-2 text-gray-900">{{ user.roles.join(', ') || $t('common.dash') }}</dd>
                    <dt class="text-gray-500">{{ $t('user.permissions') }}</dt>
                    <dd class="col-span-2 text-gray-900">
                        <ul class="space-y-0.5">
                            <li>{{ user.can_student_insert ? '✓' : '—' }} {{ $t('user.canAddStudents') }}</li>
                            <li>{{ user.can_student_edit ? '✓' : '—' }} {{ $t('user.canEditStudents') }}</li>
                            <li>{{ user.can_student_delete ? '✓' : '—' }} {{ $t('user.canDeleteStudents') }}</li>
                            <li>{{ user.can_reset_test_results ? '✓' : '—' }} {{ $t('user.canResetResults') }}</li>
                        </ul>
                    </dd>
                    <template v-if="user.image_url || user.file_url">
                        <dt class="text-gray-500">{{ $t('user.file') }}</dt>
                        <dd class="col-span-2 text-gray-900">
                            <a v-if="user.image_url" :href="user.image_url" target="_blank" class="text-blue-600 hover:underline">{{ $t('user.image') }}</a>
                            <a v-if="user.file_url" :href="user.file_url" target="_blank" class="ml-3 text-blue-600 hover:underline">{{ $t('user.file') }}</a>
                        </dd>
                    </template>
                </dl>
                <RouterLink :to="{ name: 'users' }" class="mt-6 inline-block rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50">
                    {{ $t('common.back') }}
                </RouterLink>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="text-sm font-medium text-gray-700">{{ $t('user.assignments') }}</h2>

                <ul class="mt-3 space-y-2">
                    <li v-for="a in user.assignments" :key="a.id"
                        class="flex items-start justify-between rounded-md border border-gray-100 px-3 py-2 text-sm">
                        <div>
                            <span class="font-medium">{{ a.role.name ?? a.role.key }}</span>
                            <span class="text-gray-400"> · {{ a.season.name }}</span>
                            <div v-if="a.schools.length" class="text-xs text-gray-500">
                                {{ $t('venue.title') }}: {{ a.schools.map((s) => s.name).join(', ') }}
                            </div>
                        </div>
                        <button class="text-red-600 hover:underline" @click="removeAssignment(a.id)">{{ $t('common.remove') }}</button>
                    </li>
                    <li v-if="user.assignments.length === 0" class="text-sm text-gray-400">{{ $t('user.noAssignments') }}</li>
                </ul>

                <form class="mt-4 space-y-3 border-t border-gray-100 pt-4" @submit.prevent="submitAssignment">
                    <select v-model="assignForm.role_id" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <option :value="null" disabled>{{ $t('user.rolePlaceholder') }}</option>
                        <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option>
                    </select>

                    <div v-if="needsSingleSchool">
                        <label class="block text-xs text-gray-500">{{ $t('user.venueSingle') }}</label>
                        <select v-model="singleSchool" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                            <option :value="null" disabled>{{ $t('user.venuePlaceholder') }}</option>
                            <option v-for="s in assignSchools" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                    </div>

                    <div v-if="needsMultiSchool">
                        <label class="block text-xs text-gray-500">{{ $t('user.venueMulti') }}</label>
                        <select v-model="assignForm.school_ids" multiple
                            class="mt-1 h-28 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                            <option v-for="s in assignSchools" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                    </div>

                    <p v-if="(needsSingleSchool || needsMultiSchool) && !user.country.id" class="text-xs text-amber-600">
                        {{ $t('user.noCountryWarn') }}
                    </p>

                    <div class="flex items-center gap-3">
                        <button type="submit" :disabled="assignSaving"
                            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                            {{ $t('user.assign') }}
                        </button>
                        <span v-if="assignError" class="text-sm text-red-600">{{ assignError }}</span>
                    </div>
                </form>
            </div>
        </div>
    </section>
</template>
