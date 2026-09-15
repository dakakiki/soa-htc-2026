<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { IconBrandAndroid, IconBrandApple, IconDeviceMobileDown, IconShare2, IconX } from '@tabler/icons-vue';

/**
 * The offer to install, and the only one the site makes.
 *
 * A browser that can install this site says so to the page and to nobody else:
 * Chrome dropped the bar it used to show on its own, so a site that ignores
 * `beforeinstallprompt` is a site whose visitors find installation only by
 * opening the browser's own menu — which is to say, never. We keep the event
 * and spend it on a button of our own.
 *
 * 🪤 Safari has no such event and will not get one. On an iPhone nothing can
 * raise the install dialog, because there is no install dialog: the path is
 * Share → Add to Home Screen, by hand. So iOS gets the same button and a panel
 * that shows the way, on tap — the owner's rule of 2026-09-15 is that nothing
 * about this appears unasked.
 *
 * Phones and tablets only. The season strip carries the round on a desktop and
 * has room beside it; on a phone that strip is hidden in the student shell
 * (`StudentLayout`), and floating clear of the layout is the one position that
 * works in both shells without moving anything already on the screen.
 */

/** Chromium's install event. Not in the DOM lib, because no standard has it. */
interface BeforeInstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    readonly userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

/*
 * 🔴 PRIVREMENO, ZA SREDJIVANJE IZGLEDA (2026-09-15) — MORA IZAC PRE SPAJANJA.
 * Dok se dogovaraju izgled i tekst, dugme stoji na svakoj sirini i bez obzira
 * na to da li pregledac nudi instalaciju; na `dev.lcl` on to nikad ne nudi,
 * jer nudi samo preko https. Na `false` se vraca pravo ponasanje.
 */
const DESIGN_PREVIEW = true;

const { t } = useI18n();

const deferred = ref<BeforeInstallPromptEvent | null>(null);
const installed = ref(false);
const showIosPanel = ref(false);

/**
 * Already installed, so there is nothing to offer. Asked two ways because the
 * two platforms answer differently: the media query is the standard, and
 * `navigator.standalone` is what an older iOS home-screen window sets.
 */
function detectInstalled(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    const standalone = window.matchMedia?.('(display-mode: standalone)').matches === true;

    return standalone || (window.navigator as { standalone?: boolean }).standalone === true;
}

/**
 * An iPhone or iPad. Every browser there is WebKit underneath and every one of
 * them installs the same way, so the test is the platform rather than the brand
 * of browser wrapped around it. iPadOS reports itself as a Mac, and is told
 * apart by the touch points a Mac does not have.
 */
const isIos = computed<boolean>(() => {
    if (typeof navigator === 'undefined') {
        return false;
    }

    const ua = navigator.userAgent;

    return /iPad|iPhone|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
});

/**
 * Shown when the browser has told us it can install, or when we are on iOS and
 * can at least show the way. Never when it is already installed: an offer to do
 * what is done reads as a bug.
 */
const visible = computed<boolean>(() => DESIGN_PREVIEW || (!installed.value && (deferred.value !== null || isIos.value)));

function onBeforeInstallPrompt(event: Event): void {
    // Keeping the event is what stops the browser from handling it its own way,
    // and what lets us raise the dialog later, from a tap the visitor chose.
    event.preventDefault();
    deferred.value = event as BeforeInstallPromptEvent;
}

function onInstalled(): void {
    installed.value = true;
    deferred.value = null;
    showIosPanel.value = false;
}

/**
 * The Android half. Spending the kept event is the only way to raise the system
 * dialog, and the browser allows it once — so the offer goes with it.
 */
async function installAndroid(): Promise<void> {
    const event = deferred.value;

    if (event === null) {
        return;
    }

    await event.prompt();
    deferred.value = null;
}

/** The iPhone half. There is no dialog to raise, so we show the way instead. */
function showIos(): void {
    showIosPanel.value = !showIosPanel.value;
}

onMounted(() => {
    installed.value = detectInstalled();
    window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt);
    window.addEventListener('appinstalled', onInstalled);
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeinstallprompt', onBeforeInstallPrompt);
    window.removeEventListener('appinstalled', onInstalled);
});
</script>

<template>
    <div v-if="visible" class="fixed bottom-5 right-5 z-40 flex flex-col items-end gap-3"
        :class="DESIGN_PREVIEW ? '' : 'lg:hidden'">
        <div
            v-if="showIosPanel"
            class="w-[17.5rem] rounded-xl border border-brand-palette-4/10 bg-white p-4 shadow-xl"
        >
            <div class="flex items-start gap-3">
                <p class="flex-1 text-[15px] font-medium leading-tight">{{ t('public.install.iosTitle') }}</p>
                <button
                    type="button"
                    :aria-label="t('public.install.close')"
                    class="-mr-1 -mt-1 grid h-7 w-7 shrink-0 place-items-center rounded-full text-brand-palette-4/50 transition hover:bg-brand-palette-4/5"
                    @click="showIosPanel = false"
                >
                    <IconX :size="16" :stroke-width="1.8" />
                </button>
            </div>

            <ol class="mt-3 space-y-2.5">
                <li class="flex gap-3">
                    <span class="font-mono text-[11px] leading-5 text-brand-palette-4/45">1</span>
                    <span class="flex-1 text-[13px] leading-5 text-brand-palette-4/75">
                        {{ t('public.install.iosStep1Before') }}
                        <IconShare2 :size="15" :stroke-width="1.8" class="mx-0.5 inline-block align-[-2px]" />
                        {{ t('public.install.iosStep1After') }}
                    </span>
                </li>
                <li class="flex gap-3">
                    <span class="font-mono text-[11px] leading-5 text-brand-palette-4/45">2</span>
                    <span class="flex-1 text-[13px] leading-5 text-brand-palette-4/75">{{ t('public.install.iosStep2') }}</span>
                </li>
                <li class="flex gap-3">
                    <span class="font-mono text-[11px] leading-5 text-brand-palette-4/45">3</span>
                    <span class="flex-1 text-[13px] leading-5 text-brand-palette-4/75">{{ t('public.install.iosStep3') }}</span>
                </li>
            </ol>
        </div>

        <div class="flex items-center gap-2 rounded-full bg-brand-palette-4 py-2 pl-4 pr-2 text-white shadow-lg">
            <IconDeviceMobileDown :size="20" :stroke-width="1.7" aria-hidden="true" />

            <span class="font-mono text-[11px] uppercase tracking-[0.12em]">{{ t('public.install.button') }}</span>

            <span class="ml-1 h-5 w-px bg-white/20" aria-hidden="true"></span>

            <button
                type="button"
                :aria-label="t('public.install.android')"
                class="grid h-9 w-9 place-items-center rounded-full bg-white/10 transition hover:bg-brand-palette-2 hover:text-brand-palette-4 active:scale-95"
                @click="installAndroid"
            >
                <IconBrandAndroid :size="19" :stroke-width="1.7" />
            </button>

            <button
                type="button"
                :aria-label="t('public.install.ios')"
                :aria-expanded="showIosPanel"
                class="grid h-9 w-9 place-items-center rounded-full bg-white/10 transition hover:bg-brand-palette-2 hover:text-brand-palette-4 active:scale-95"
                @click="showIos"
            >
                <IconBrandApple :size="19" :stroke-width="1.7" />
            </button>
        </div>
    </div>
</template>
