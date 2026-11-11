<?php

namespace App\Support\Branches;

use DomainException;

/**
 * Raised when a change would break a branch invariant: Main must stay active
 * and unique, branches are never deleted, and a store's branch is frozen once
 * the store has stock movements or documents (plan 12, invariant I5). The
 * messages are plain and fixable so controllers can show them as validation
 * errors instead of a 500.
 *
 * It extends DomainException, not InvalidArgumentException, so the existing
 * `catch (InvalidArgumentException)` blocks in controllers do not see it.
 * Callers that post across branches (BR05 onwards) must catch it alongside
 * InvalidArgumentException (per-branch imbalance, unknown or inactive
 * branch) and AuthorizationException (branch outside the actor's access).
 */
class BranchRuleViolation extends DomainException
{
    /**
     * The form field the message belongs to, when it is about one field
     * (controllers show the message under it); null otherwise.
     */
    public ?string $field = null;

    public function forField(string $field): self
    {
        $this->field = $field;

        return $this;
    }

    public static function mainCannotBeDeactivated(): self
    {
        return new self('The Main branch cannot be deactivated.');
    }

    public static function mainCannotBeChanged(): self
    {
        return new self('A branch cannot be made, or stop being, the Main branch. Exactly one Main branch exists.');
    }

    public static function mainAlreadyExists(): self
    {
        return new self('A Main branch already exists. Only one branch can be Main.');
    }

    public static function branchCannotBeDeleted(): self
    {
        return new self('Branches are never deleted. Deactivate the branch instead.');
    }

    public static function storeBranchIsLocked(string $storeName): self
    {
        return new self(sprintf(
            'The branch of store "%s" cannot be changed because the store already has stock movements or documents. '
            .'Create a new store under the other branch and move stock with a transfer.',
            $storeName
        ));
    }

    /**
     * Per-branch numbering (plan 12, BR20): once a branch series has numbered
     * a document its code is part of every printed number in it, so changing
     * the code would make reprints differ from the issued originals.
     */
    public static function seriesCodeIsLocked(string $branchName): self
    {
        return (new self(sprintf(
            'The series code of "%s" cannot be changed because documents have already been numbered with it.',
            $branchName
        )))->forField('series_code');
    }

    public static function seriesCodeIsRequired(string $branchName): self
    {
        return (new self(sprintf(
            'The series code of "%s" cannot be cleared while a fiscal year numbers documents separately per branch.',
            $branchName
        )))->forField('series_code');
    }

    public static function noUsableBranch(): self
    {
        return new self('You are not assigned to any active branch. Ask an administrator to give you access to a branch.');
    }

    /**
     * A voucher spans branches and needs Inter-Branch Clearing lines, but the
     * tenant has no clearing account yet. Single-branch vouchers never get
     * here. The account is resolved by code, so the message names the code
     * an administrator must give it (the tenant migrations normally add it).
     */
    public static function clearingAccountMissing(string $accountName, ?string $accountCode = null): self
    {
        if ($accountCode === null) {
            return new self(sprintf(
                'This entry spans more than one branch and needs the "%s" account, which does not exist yet. '
                .'Add it to the chart of accounts before posting across branches.',
                $accountName
            ));
        }

        return new self(sprintf(
            'This entry spans more than one branch and needs the "%s" account (code %s), which does not exist yet. '
            .'Add it to the chart of accounts with that code before posting across branches.',
            $accountName,
            $accountCode
        ));
    }
}
