import { ref } from 'vue';

const DEFAULT_DURATION = 4000;

const toasts = ref([]);
let nextId = 0;

function dismiss(id) {
    toasts.value = toasts.value.filter((item) => item.id !== id);
}

function toast({ message, variant = 'default', duration = DEFAULT_DURATION }) {
    const id = ++nextId;
    toasts.value.push({ id, message, variant });

    if (duration > 0) {
        setTimeout(() => dismiss(id), duration);
    }

    return id;
}

export function useToast() {
    return { toasts, toast, dismiss };
}
