# P12 Users page and ownership transfer

Phase C | Depends on: P11 | Executor: worker, coordinator (route edits)
Design refs: section 5 in `plans/roles-permissions-entitlements.md`.

## Owned files

New: `app/Support/Permissions/OwnershipTransfer.php`, `app/Http/Requests/Tenant/Admin/TransferOwnershipRequest.php`,
`tests/Feature/Tenant/Admin/UserAssignmentTest.php`, `tests/Feature/Tenant/Admin/OwnershipTransferTest.php`.
Modified: `app/Http/Controllers/Tenant/Admin/UserController.php`, `resources/js/pages/Tenant/Admin/Users.vue`.
Coordinator: `routes/tenant-employees.php` (gates, transfer route).

## Tasks

- [ ] 1. **Assignment guard.** Replace `guardLastActiveAdmin` with owner-based rules in `UserController`:
  the owner cannot be deactivated, demoted or have their role changed; a non-owner with `users.manage` may assign
  only roles whose permission set is a **subset of their own effective set**, cannot edit the owner, cannot edit
  their own role, and cannot create a user with a broader role. Owner-only keys can never be in a role, so this
  also blocks a backdoor to them. The role list sent to the page is filtered to assignable roles.
- [ ] 2. **Ownership transfer.** `OwnershipTransfer::run(User $from, User $to)`: one transaction, requires `$to`
  active and not already owner, locks both rows, moves `is_owner`, leaves exactly one owner, logs the change.
  Route `POST /admin/users/{user}/transfer-ownership` gated by owner-only key `ownership.transfer`, requires the
  owner's current password in the request. UI: a clearly destructive "Transfer ownership" action with a
  confirmation dialog on the Users page.
- [ ] 3. **Tests.** Escalation cases (assign broader role denied, self-edit denied, owner edit denied), last-owner
  invariants, transfer keeps exactly one owner, wrong password rejected, inactive target rejected, transfer
  denied for non-owners, a manager with `users.manage` can assign a narrower role.

## Done when

No path exists for a non-owner to obtain more power than they hold, and exactly one owner always exists.
