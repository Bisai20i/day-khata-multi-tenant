import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { createPermissionChecker } from '@/lib/permissions';

/**
 * Reactive permission checks from the shared `auth.can` / `auth.isOwner` props.
 * Lookups are O(1) through a computed Set. UI gating only; the server decides.
 */
export function usePermissions() {
    const page = usePage();
    const checker = computed(() => createPermissionChecker(page.props.auth?.can, page.props.auth?.isOwner));

    return {
        can: (key) => checker.value.can(key),
        canAny: (keys) => checker.value.canAny(keys),
        isOwner: computed(() => checker.value.isOwner),
    };
}
