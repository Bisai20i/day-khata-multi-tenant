<?php

namespace App\Support\Branches\Audit;

use App\Support\Branches\BranchAudit;

/**
 * Collects what one check found across its several queries: the total
 * number of offending records (counted in SQL, never by loading them) and
 * the first BranchAudit::LIST_LIMIT descriptions, then turns that into a
 * BranchAuditResult.
 */
final class AuditFindings
{
    private int $count = 0;

    /**
     * @var list<string>
     */
    private array $items = [];

    /**
     * @param  list<string>  $items
     */
    public function add(int $count, array $items): void
    {
        $this->count += $count;

        foreach ($items as $item) {
            if (count($this->items) >= BranchAudit::LIST_LIMIT) {
                break;
            }

            $this->items[] = $item;
        }
    }

    public function addOne(string $item): void
    {
        $this->add(1, [$item]);
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * PASS with $passMessage when nothing was found, else $status with
     * $foundMessage (":count" is replaced by the total).
     *
     * @param  BranchAuditResult::WARN|BranchAuditResult::FAIL  $status
     */
    public function result(string $key, string $invariant, string $title, string $status, string $passMessage, string $foundMessage): BranchAuditResult
    {
        if ($this->count === 0) {
            return BranchAuditResult::pass($key, $invariant, $title, $passMessage);
        }

        return new BranchAuditResult(
            $key,
            $invariant,
            $title,
            $status,
            str_replace(':count', (string) $this->count, $foundMessage),
            $this->count,
            $this->items,
        );
    }
}
