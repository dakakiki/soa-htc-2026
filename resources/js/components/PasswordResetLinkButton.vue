<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconMail, IconCheck } from '@tabler/icons-vue';
import { sendPasswordResetLink } from '@/api/users';
import { apiErrorMessage } from '@/api/http';

/**
 * "Send a link" beside the password fields on the Users and Coordinators forms
 * (ADR-0063).
 *
 * It sits where an administrator is already standing when the problem comes up:
 * in the password box, about to type one in for somebody who cannot remember
 * theirs. That was the only thing there was to do before this, and it ends with
 * a password two people know, read out over a telephone.
 *
 * Deliberately not a confirmation dialog. Nothing is changed by pressing it —
 * the account keeps its password until somebody follows the link — so a dialog
 * would be asking permission for nothing.
 *
 * Both screens share one component because they share one endpoint: who may send
 * a link is the policy's answer, and a second copy of this would be the place
 * the two screens came to disagree about it.
 */
const props = defineProps<{ userId: number }>();

const { t } = useI18n();

const sending = ref(false);
const sent = ref(false);
const error = ref<string | null>(null);

async function send(): Promise<void> {
    sending.value = true;
    error.value = null;

    try {
        await sendPasswordResetLink(props.userId);
        sent.value = true;
    } catch (e) {
        // The one refusal that reaches here is the broker's own throttle — a
        // link sent to this address less than a minute ago. It says so.
        error.value = apiErrorMessage(e, t('user.resetLinkFailed'));
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <div>
        <button
            type="button"
            :disabled="sending || sent"
            class="inline-flex items-center gap-2 rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:opacity-60"
            @click="send"
        >
            <IconCheck v-if="sent" :size="16" class="text-green-600" />
            <IconMail v-else :size="16" class="text-gray-400" />
            <span>{{ sent ? $t('user.resetLinkSent') : sending ? $t('user.resetLinkSending') : $t('user.resetLink') }}</span>
        </button>

        <p v-if="error" class="mt-2 text-sm text-red-600">{{ error }}</p>
        <!-- Said after the fact rather than as a hint under an unpressed button:
             what an administrator needs to know here is that they are now waiting
             on somebody else, not that a mail exists. -->
        <p v-else-if="sent" class="mt-2 text-sm text-gray-500">{{ $t('user.resetLinkNote') }}</p>
    </div>
</template>
