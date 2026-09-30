<script setup>
import { computed, reactive } from 'vue';
import { ChevronDown, ChevronRight } from '@lucide/vue';
import { changedKeys, keysOf, selectionState, setKeys } from '@/lib/roleMatrix';

/**
 * The role editor's permission grid: modules, then groups, then keys, as the
 * server sends them (already narrowed to the tenant's entitled modules, with
 * owner-only keys removed). Selection rules live in lib/roleMatrix.js; this
 * component only renders them. Keys whose state differs from `original` are
 * highlighted so the owner can see what a save will change.
 */
const props = defineProps({
    modules: { type: Array, default: () => [] },
    modelValue: { type: Array, default: () => [] },
    original: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const available = computed(() => keysOf(props.modules));
const selectedSet = computed(() => new Set(props.modelValue));
const changed = computed(() => changedKeys(props.original, props.modelValue));
const originalSet = computed(() => new Set(props.original));

const collapsed = reactive({});

function groupKeys(group) {
    return group.permissions.map((permission) => permission.key);
}

function moduleKeys(module) {
    return keysOf([module]);
}

function stateOf(keys) {
    return selectionState(props.modelValue, keys);
}

function countOf(keys) {
    return keys.filter((key) => selectedSet.value.has(key)).length;
}

function apply(keys, checked) {
    if (props.disabled) return;
    emit('update:modelValue', setKeys(props.modelValue, keys, checked, available.value));
}

function changedCountOf(keys) {
    return keys.filter((key) => changed.value.has(key)).length;
}

function toggleCollapsed(moduleKey) {
    collapsed[moduleKey] = !collapsed[moduleKey];
}

function changeLabel(key) {
    return originalSet.value.has(key) ? 'removed' : 'added';
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <section v-for="module in modules" :key="module.module" class="border-[1.5px] border-border bg-white">
            <header class="flex items-center gap-3 border-b-[1.5px] border-border bg-bg-subtle px-3 py-2">
                <button
                    type="button"
                    class="flex h-6 w-6 items-center justify-center text-text-muted hover:text-text-base"
                    :aria-expanded="!collapsed[module.module]"
                    :aria-label="`${collapsed[module.module] ? 'Expand' : 'Collapse'} ${module.label}`"
                    @click="toggleCollapsed(module.module)"
                >
                    <ChevronRight v-if="collapsed[module.module]" class="h-4 w-4" aria-hidden="true" />
                    <ChevronDown v-else class="h-4 w-4" aria-hidden="true" />
                </button>
                <label class="flex flex-1 cursor-pointer items-center gap-2 text-sm font-bold text-text-base">
                    <input
                        type="checkbox"
                        class="size-4 border-[1.5px] border-border"
                        :checked="stateOf(moduleKeys(module)) === 'all'"
                        :indeterminate="stateOf(moduleKeys(module)) === 'some'"
                        :disabled="disabled"
                        :aria-label="`Select all ${module.label} permissions`"
                        @change="apply(moduleKeys(module), $event.target.checked)"
                    />
                    {{ module.label }}
                </label>
                <span v-if="changedCountOf(moduleKeys(module))" class="bg-warning-bg px-2 py-0.5 text-[11px] font-bold text-warning-text">
                    {{ changedCountOf(moduleKeys(module)) }} changed
                </span>
                <span class="text-xs text-text-faint">{{ countOf(moduleKeys(module)) }} of {{ moduleKeys(module).length }}</span>
            </header>

            <div v-show="!collapsed[module.module]" class="flex flex-col divide-y divide-border">
                <div v-for="group in module.groups" :key="group.group" class="px-3 py-2.5">
                    <label class="mb-2 flex cursor-pointer items-center gap-2 text-[12.5px] font-semibold text-text-muted">
                        <input
                            type="checkbox"
                            class="size-4 border-[1.5px] border-border"
                            :checked="stateOf(groupKeys(group)) === 'all'"
                            :indeterminate="stateOf(groupKeys(group)) === 'some'"
                            :disabled="disabled"
                            :aria-label="`Select all ${group.group} permissions`"
                            @change="apply(groupKeys(group), $event.target.checked)"
                        />
                        {{ group.group }}
                        <span class="font-normal text-text-faint">({{ countOf(groupKeys(group)) }} of {{ group.permissions.length }})</span>
                    </label>

                    <div class="grid grid-cols-1 gap-x-4 gap-y-1 pl-6 sm:grid-cols-2 lg:grid-cols-3">
                        <label
                            v-for="permission in group.permissions"
                            :key="permission.key"
                            class="flex cursor-pointer items-start gap-2 px-1.5 py-1 text-[13px] text-text-base"
                            :class="changed.has(permission.key) ? 'bg-warning-bg' : ''"
                            :title="permission.key"
                        >
                            <input
                                type="checkbox"
                                class="mt-0.5 size-4 shrink-0 border-[1.5px] border-border"
                                :checked="selectedSet.has(permission.key)"
                                :disabled="disabled"
                                @change="apply([permission.key], $event.target.checked)"
                            />
                            <span>
                                {{ permission.label }}
                                <span v-if="changed.has(permission.key)" class="ml-1 text-[11px] font-bold text-warning-text">
                                    ({{ changeLabel(permission.key) }})
                                </span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </section>

        <p v-if="modules.length === 0" class="text-sm text-text-faint">No permissions are available for this company.</p>
    </div>
</template>
