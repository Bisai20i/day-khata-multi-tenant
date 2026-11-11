<?php

namespace App\Support\Branches\Audit;

/**
 * One line of the `branches:audit` report (plan 12, BR23): a check, its
 * outcome and, when it found something, how many records and a capped list
 * of them (BranchAudit::LIST_LIMIT). Plain data, so the command, a future
 * admin page or a test can all render or assert it the same way.
 *
 * FAIL breaks an invariant (the command exits non-zero); WARN is something
 * to look at before a pilot or a year end but not a broken book.
 */
final readonly class BranchAuditResult
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /**
     * @param  self::PASS|self::WARN|self::FAIL  $status
     * @param  list<string>  $items  At most BranchAudit::LIST_LIMIT, the first offenders found.
     */
    public function __construct(
        public string $key,
        public string $invariant,
        public string $title,
        public string $status,
        public string $message,
        public int $count = 0,
        public array $items = [],
    ) {}

    public static function pass(string $key, string $invariant, string $title, string $message): self
    {
        return new self($key, $invariant, $title, self::PASS, $message);
    }

    public static function warn(string $key, string $invariant, string $title, string $message): self
    {
        return new self($key, $invariant, $title, self::WARN, $message);
    }

    public static function fail(string $key, string $invariant, string $title, string $message): self
    {
        return new self($key, $invariant, $title, self::FAIL, $message);
    }

    public function isFailure(): bool
    {
        return $this->status === self::FAIL;
    }

    public function isWarning(): bool
    {
        return $this->status === self::WARN;
    }

    /**
     * @return array{key: string, invariant: string, title: string, status: string, message: string, count: int, items: list<string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'invariant' => $this->invariant,
            'title' => $this->title,
            'status' => $this->status,
            'message' => $this->message,
            'count' => $this->count,
            'items' => $this->items,
        ];
    }
}
