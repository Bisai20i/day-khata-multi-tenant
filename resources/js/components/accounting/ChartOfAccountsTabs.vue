<script setup>
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    current: {
        type: String,
        required: true, // 'accounts' | 'groups' | 'subgroups'
    },
});

const tabs = [
    { id: 'accounts', label: 'Accounts', href: '/accounts' },
    { id: 'groups', label: 'Account Groups', href: '/account-groups' },
    { id: 'subgroups', label: 'Account Subgroups', href: '/account-subgroups' },
];
</script>

<template>
    <div class="mb-4 flex items-center gap-1 border-b-[1.5px] border-border">
        <Link
            v-for="tab in tabs"
            :key="tab.id"
            :href="tab.href"
            :class="[
                'shrink-0 border-b-2 px-3 py-2 whitespace-nowrap text-[12.5px] font-bold transition-colors duration-150',
                props.current === tab.id
                    ? 'border-primary text-primary'
                    : 'border-transparent text-text-muted hover:text-text-base',
            ]"
        >
            {{ tab.label }}
        </Link>
    </div>
</template>
