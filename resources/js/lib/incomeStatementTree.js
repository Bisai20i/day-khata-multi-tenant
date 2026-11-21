/**
 * Keys for the income statement's expandable group tree. A key names one
 * group (section, group) or one sub-group (section, group, sub-group) so the
 * page can keep a single list of open nodes and "Expand all" can rebuild it.
 *
 * `groups` are the server's nodes: { name, total, by_branch, rows, subgroups }.
 */
// The unit separator cannot occur in an account group name, so a group called
// "A/B" never collides with sub-group "B" of group "A".
const SEP = '\x1f';

export function groupKey(section, group) {
    return `${section}${SEP}${group.name}`;
}

export function subgroupKey(section, group, subgroup) {
    return `${section}${SEP}${group.name}${SEP}${subgroup.name}`;
}

/** Every openable key of one section: its groups and their sub-groups. */
export function collectKeys(section, groups) {
    const keys = [];

    for (const group of groups ?? []) {
        keys.push(groupKey(section, group));

        for (const subgroup of group.subgroups ?? []) {
            keys.push(subgroupKey(section, group, subgroup));
        }
    }

    return keys;
}
