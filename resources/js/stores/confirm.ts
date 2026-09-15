import { defineStore } from 'pinia';
import { ref } from 'vue';

/**
 * Global confirmation dialog. Call `ask()` and await the boolean result;
 * a single <ConfirmDialog /> mounted in App renders the modal.
 */
export const useConfirmStore = defineStore('confirm', () => {
    const open = ref(false);
    const title = ref('');
    const message = ref('');
    /**
     * Opt-in styling for an action that destroys data and cannot be undone: the
     * dialog turns red and warns instead of merely asking. Everyday deletes stay
     * on the neutral variant — a warning every screen shouts is one nobody reads.
     */
    const danger = ref(false);
    /** Overrides the confirm button's label, which otherwise reads "Delete". */
    const confirmLabel = ref('');
    /**
     * What is being confirmed. The neutral dialog was written for deletions and
     * says so in its icon and in the red of its button; anything else asking
     * through it was asking "Delete?" with different words on top.
     */
    const kind = ref<'delete' | 'send'>('delete');
    let resolver: ((value: boolean) => void) | null = null;

    function ask(options: {
        title?: string;
        message: string;
        danger?: boolean;
        confirmLabel?: string;
        kind?: 'delete' | 'send';
    }): Promise<boolean> {
        title.value = options.title ?? '';
        message.value = options.message;
        danger.value = options.danger ?? false;
        confirmLabel.value = options.confirmLabel ?? '';
        kind.value = options.kind ?? 'delete';
        open.value = true;
        return new Promise<boolean>((resolve) => {
            resolver = resolve;
        });
    }

    function settle(value: boolean): void {
        open.value = false;
        resolver?.(value);
        resolver = null;
    }

    return {
        open,
        title,
        message,
        danger,
        confirmLabel,
        kind,
        ask,
        confirm: () => settle(true),
        cancel: () => settle(false),
    };
});
