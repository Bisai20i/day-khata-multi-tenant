<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Accounting\ProjectRequest;
use App\Models\Project;
use App\Support\Branches\BranchContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master list of projects that journal lines can be tagged with. Gated per
 * route: `projects.view` to open the page, `projects.manage` to change it.
 *
 * A project that already carries journal lines is never deleted (the foreign
 * key would null project_id on those lines and silently change past
 * statements); it is marked completed instead.
 */
class ProjectController extends Controller
{
    /** Tables with a project_id column besides journal_voucher_lines. */
    private const DOCUMENT_TABLES = [
        'sales', 'sales_returns', 'receipts',
        'purchases', 'purchase_returns', 'payments',
        'capital_purchases', 'capital_purchase_settlements', 'capital_sales',
    ];

    public function index(): Response
    {
        return Inertia::render('Tenant/Accounting/Projects/Index', [
            // Counted across every branch: the lines scope would hide other
            // branches' postings from a branch-restricted user.
            'projects' => BranchContext::unrestricted(fn () => Project::query()
                ->withCount('lines')
                ->orderBy('code')
                ->get())
                ->map(fn (Project $project) => [
                    'id' => $project->id,
                    'code' => $project->code,
                    'name' => $project->name,
                    'name_np' => $project->name_np,
                    'client' => $project->client,
                    'contract_value' => $project->contract_value,
                    'budget' => $project->budget,
                    'start_date' => $project->start_date?->toDateString(),
                    'end_date' => $project->end_date?->toDateString(),
                    'status' => $project->status,
                    'notes' => $project->notes,
                    'lines_count' => $project->lines_count,
                ])
                ->all(),
        ]);
    }

    public function store(ProjectRequest $request): RedirectResponse
    {
        try {
            $project = Project::create($request->projectAttributes());
        } catch (UniqueConstraintViolationException) {
            // Lost a race with another request that inserted the same code.
            throw ValidationException::withMessages(['code' => 'A project with this code already exists.']);
        }

        return redirect()->route('tenant.projects.index')->with('status', "Project \"{$project->code}\" added.");
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        try {
            $project->update($request->projectAttributes());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'A project with this code already exists.']);
        }

        return redirect()->route('tenant.projects.index')->with('status', "Project \"{$project->code}\" updated.");
    }

    public function destroy(Project $project): RedirectResponse
    {
        // The row lock serialises with a journal line being posted against this
        // project (the line's foreign key check waits on the parent row).
        $message = DB::transaction(function () use ($project): string {
            $locked = Project::query()->whereKey($project->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return "Project \"{$project->code}\" was already deleted.";
            }

            if ($this->isInUse($locked)) {
                $locked->update(['status' => Project::STATUS_COMPLETED]);

                return "Project \"{$locked->code}\" has journal entries, so it was marked completed instead of deleted.";
            }

            $locked->delete();

            return "Project \"{$locked->code}\" deleted.";
        });

        return redirect()->route('tenant.projects.index')->with('status', $message);
    }

    /**
     * True when any ledger line or document points at the project, in any
     * branch. Deleting it would null project_id on those rows (the foreign
     * key is nullOnDelete) and silently change past statements.
     */
    private function isInUse(Project $project): bool
    {
        if (BranchContext::unrestricted(fn (): bool => $project->lines()->exists())) {
            return true;
        }

        foreach (self::DOCUMENT_TABLES as $table) {
            if (DB::table($table)->where('project_id', $project->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }
}
