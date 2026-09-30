<?php

namespace App\Support\Permissions;

/**
 * The one reader of config/permissions.php. Roles store permission keys as
 * JSON and tenants store enabled module keys, so every "is this key real?",
 * "which module does it belong to?" and "which keys can this tenant use?"
 * question has to be answered from the same normalised view of the config,
 * otherwise a typo in a role or a half-enabled module silently grants or
 * hides the wrong thing.
 *
 * Everything here is static and memoized per process: the config is a
 * constant for the lifetime of a request, and permission checks run on
 * every gated route, so we normalise once and never touch the database.
 * Tests that swap the config call flush() so the next read rebuilds.
 *
 * @phpstan-type ModuleDef array{label: string, always_on: bool, requires: list<string>}
 * @phpstan-type PermissionDef array{module: string, group: string, label: string, owner_only: bool, legacy: list<string>}
 */
class PermissionCatalog
{
    /** @var array<string, ModuleDef>|null */
    private static ?array $modules = null;

    /** @var array<string, PermissionDef>|null */
    private static ?array $permissions = null;

    /** @var array<string, list<string>>|null module key => permission keys, config order */
    private static ?array $keysByModule = null;

    /**
     * Drop the memoized view so the next call re-reads config('permissions').
     */
    public static function flush(): void
    {
        self::$modules = null;
        self::$permissions = null;
        self::$keysByModule = null;
    }

    /**
     * Modules in config order, with the optional flags defaulted.
     *
     * @return array<string, ModuleDef>
     */
    public static function modules(): array
    {
        if (self::$modules === null) {
            $normalized = [];
            foreach ((array) config('permissions.modules', []) as $key => $def) {
                $normalized[(string) $key] = [
                    'label' => (string) ($def['label'] ?? $key),
                    'always_on' => (bool) ($def['always_on'] ?? false),
                    'requires' => array_values(array_map('strval', $def['requires'] ?? [])),
                ];
            }
            self::$modules = $normalized;
        }

        return self::$modules;
    }

    /**
     * Permissions in config order (which is the role editor's display order),
     * with the optional flags defaulted.
     *
     * @return array<string, PermissionDef>
     */
    public static function permissions(): array
    {
        if (self::$permissions === null) {
            $normalized = [];
            foreach ((array) config('permissions.permissions', []) as $key => $def) {
                $normalized[(string) $key] = [
                    'module' => (string) ($def['module'] ?? ''),
                    'group' => (string) ($def['group'] ?? ''),
                    'label' => (string) ($def['label'] ?? $key),
                    'owner_only' => (bool) ($def['owner_only'] ?? false),
                    'legacy' => array_values(array_map('strval', $def['legacy'] ?? [])),
                ];
            }
            self::$permissions = $normalized;
        }

        return self::$permissions;
    }

    public static function has(string $key): bool
    {
        return isset(self::permissions()[$key]);
    }

    public static function moduleOf(string $key): ?string
    {
        return self::permissions()[$key]['module'] ?? null;
    }

    /**
     * Unknown keys are not owner-only: they are simply not grantable, and
     * has() is the check that rejects them.
     */
    public static function isOwnerOnly(string $key): bool
    {
        return self::permissions()[$key]['owner_only'] ?? false;
    }

    /**
     * Every permission key belonging to any of the given modules, in config
     * order within each module. Unknown modules contribute nothing.
     *
     * @param  list<string>  $modules
     * @return list<string>
     */
    public static function keysForModules(array $modules): array
    {
        $map = self::keysByModule();
        $keys = [];
        foreach (array_unique($modules) as $module) {
            if (isset($map[$module])) {
                $keys = array_merge($keys, $map[$module]);
            }
        }

        return $keys;
    }

    /**
     * The modules a tenant effectively has: what it enabled, plus every
     * always_on module, plus everything those transitively require (so
     * enabling POS cannot leave Sales off). Unknown keys are dropped, the
     * walk is cycle-safe, and the result follows config order so it is
     * deterministic regardless of the order the tenant stored them in.
     *
     * @param  list<string>  $enabled
     * @return list<string>
     */
    public static function resolveModules(array $enabled): array
    {
        $modules = self::modules();

        $pending = array_values(array_filter(
            array_map('strval', $enabled),
            fn (string $key): bool => isset($modules[$key]),
        ));
        foreach ($modules as $key => $def) {
            if ($def['always_on']) {
                $pending[] = $key;
            }
        }

        $resolved = [];
        while ($pending !== []) {
            $key = array_pop($pending);
            if (isset($resolved[$key]) || ! isset($modules[$key])) {
                continue;
            }
            $resolved[$key] = true;
            foreach ($modules[$key]['requires'] as $required) {
                $pending[] = $required;
            }
        }

        return array_values(array_filter(
            array_keys($modules),
            fn (string $key): bool => isset($resolved[$key]),
        ));
    }

    /**
     * Keys that may be put into a role by anyone: everything except the
     * owner-only ones, which are never stored in a role at all.
     *
     * @return list<string>
     */
    public static function grantable(): array
    {
        return array_keys(array_filter(
            self::permissions(),
            fn (array $def): bool => ! $def['owner_only'],
        ));
    }

    /**
     * @return list<string>
     */
    public static function ownerOnly(): array
    {
        return array_keys(array_filter(
            self::permissions(),
            fn (array $def): bool => $def['owner_only'],
        ));
    }

    /**
     * The role editor's tree: modules in config order, each holding its
     * groups in first-seen order, each holding its permissions in config
     * order. Modules with no permissions are omitted.
     *
     * @return list<array{
     *     module: string,
     *     label: string,
     *     groups: list<array{
     *         group: string,
     *         permissions: list<array{key: string, label: string, owner_only: bool}>
     *     }>
     * }>
     */
    public static function grouped(): array
    {
        $tree = [];
        foreach (self::modules() as $moduleKey => $module) {
            $tree[$moduleKey] = ['module' => $moduleKey, 'label' => $module['label'], 'groups' => []];
        }

        foreach (self::permissions() as $key => $def) {
            if (! isset($tree[$def['module']])) {
                continue;
            }
            $tree[$def['module']]['groups'][$def['group']] ??= ['group' => $def['group'], 'permissions' => []];
            $tree[$def['module']]['groups'][$def['group']]['permissions'][] = [
                'key' => $key,
                'label' => $def['label'],
                'owner_only' => $def['owner_only'],
            ];
        }

        $result = [];
        foreach ($tree as $module) {
            if ($module['groups'] === []) {
                continue;
            }
            $module['groups'] = array_values($module['groups']);
            $result[] = $module;
        }

        return $result;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function keysByModule(): array
    {
        if (self::$keysByModule === null) {
            $map = [];
            foreach (self::permissions() as $key => $def) {
                $map[$def['module']][] = $key;
            }
            self::$keysByModule = $map;
        }

        return self::$keysByModule;
    }
}
