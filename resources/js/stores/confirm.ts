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
    let resolver: ((value: boolean) => void) | null = null;

    function ask(options: { title?: string; message: string }): Promise<boolean> {
        title.value = options.title ?? '';
        message.value = options.message;
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
        ask,
        confirm: () => settle(true),
        cancel: () => settle(false),
    };
});
