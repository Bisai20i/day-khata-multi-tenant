import { reactive, watchEffect } from 'vue';

/**
 * Shared singleton, not per-component state: AppLayout is a persistent
 * Inertia layout (declared via `defineOptions({ layout: AppLayout })` on
 * each page) so it stays mounted across navigations instead of being torn
 * down and rebuilt on every visit - that's what keeps the sidebar's scroll
 * position and open/closed category state intact when switching pages.
 * Because it no longer remounts, per-visit chrome (the header title, and
 * Pos.vue's fullscreen focus mode) can't be passed down as ordinary props
 * from a page that gets swapped out - both sides read/write this instead.
 */
const chrome = reactive({
    title: '',
    fullscreen: false,
});

/**
 * Call once per page's <script setup>. `title` may be a plain string or a
 * getter (`() => \`Edit ${tenant.company_name}\``) for titles that depend on
 * reactive props - either way it's kept in sync via watchEffect for as long
 * as the page component is mounted.
 */
export function useLayoutChrome(title) {
    watchEffect(() => {
        chrome.title = typeof title === 'function' ? title() : title;
    });
    return chrome;
}
