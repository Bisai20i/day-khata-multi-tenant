<?php

namespace App\Rules;

use App\Models\JournalVoucher;
use App\Models\Project;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The id must name a project that can still take new entries. Completed and
 * on-hold projects keep everything already posted to them but accept nothing
 * new (JournalVoucher::write() enforces the same rule at posting; this gives
 * the form a field-level message first). A blank value means "no project".
 * Linked returns and settlements pass their original's project to $alsoAllow.
 */
class OpenProject implements ValidationRule
{
    /**
     * @param  int|string|null  $alsoAllow  The project of the document a return or settlement adjusts: it stays
     *                                      selectable (and valid) even when completed, so the adjustment can follow its original.
     */
    public function __construct(private readonly int|string|null $alsoAllow = null) {}

    /**
     * Projects already looked up by this rule instance, keyed by the submitted
     * value. A `lines.*.project_id` rule is one instance for every line, so a
     * voucher with many lines on a few projects reads each project once.
     *
     * @var array<string, Project|null>
     */
    private array $lookups = [];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $key = (string) $value;
        $project = array_key_exists($key, $this->lookups)
            ? $this->lookups[$key]
            : ($this->lookups[$key] = Project::query()->whereKey($value)->first(['id', 'code', 'status']));

        if ($project === null) {
            $fail('The selected project does not exist.');

            return;
        }

        if ($project->status !== Project::STATUS_ACTIVE && ! JournalVoucher::isSameProject($project->id, $this->alsoAllow)) {
            $state = $project->status === Project::STATUS_COMPLETED ? 'completed' : 'on hold';

            $fail("Project \"{$project->code}\" is {$state} and cannot take new entries.");
        }
    }
}
