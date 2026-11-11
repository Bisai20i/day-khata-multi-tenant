<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ActivityLog;
use App\Models\Area;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The area handling Customer and Supplier controllers share: bulk
 * assignment from the list page and resolving the optional `area` column of
 * a bulk-import file. The two controllers differ only in the model, the
 * noun in messages and (customers) a protected walk-in record.
 */
trait HandlesPartyAreas
{
    /**
     * Sets - or, with no area, clears - the area of every selected party in
     * one transaction, then returns to the list.
     *
     * Ids that no longer exist are ignored and counted in the message. A
     * model that names a `$protectedFlag` column (the customers' `is_walk_in`)
     * never has that flag's records touched: the walk-in customer is a system
     * default with no real address to belong to. The area is validated before
     * any write, so a rejected request changes nothing.
     *
     * Writes through the query builder (no per-row model events), so one
     * audit row records the whole operation, with every affected id.
     *
     * @param  class-string<Model>  $model
     * @param  string  $indexRoute  named route of the list page to return to
     * @param  string  $noun  singular, e.g. "customer"
     */
    private function bulkAssignArea(Request $request, string $model, string $indexRoute, string $noun, ?string $protectedFlag = null): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['integer', 'min:1'],
            'area_id' => ['present', 'nullable', 'integer', 'exists:areas,id'],
        ], [
            'ids.required' => "Select at least one {$noun} first.",
            'ids.min' => "Select at least one {$noun} first.",
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $areaId = isset($data['area_id']) ? (int) $data['area_id'] : null;

        $eligible = DB::transaction(function () use ($model, $ids, $areaId, $protectedFlag): array {
            $found = [];

            foreach (array_chunk($ids, 500) as $chunk) {
                $query = $model::query()->whereIn('id', $chunk);

                if ($protectedFlag !== null) {
                    $query->where($protectedFlag, false);
                }

                $matched = (clone $query)->pluck('id')->all();

                if ($matched !== []) {
                    $query->update(['area_id' => $areaId, 'updated_at' => now()]);
                }

                array_push($found, ...$matched);
            }

            return $found;
        });

        $skipped = count($ids) - count($eligible);
        $plural = static fn (int $n): string => $n === 1 ? $noun : "{$noun}s";
        $areaName = $areaId === null ? null : Area::query()->whereKey($areaId)->value('name');

        if ($eligible !== []) {
            ActivityLog::create([
                'user_id' => Auth::guard('web')->id(),
                'action' => 'bulk_area_assigned',
                'subject_type' => (new $model)->getMorphClass(),
                'subject_id' => min($eligible),
                'description' => $areaName === null
                    ? sprintf('Cleared the area of %d %s', count($eligible), $plural(count($eligible)))
                    : sprintf('Assigned %d %s to area "%s"', count($eligible), $plural(count($eligible)), $areaName),
                'changes' => ['area_id' => $areaId, 'ids' => $eligible],
            ]);
        }

        $message = $areaName === null
            ? sprintf('Cleared the area of %d %s.', count($eligible), $plural(count($eligible)))
            : sprintf('Assigned %d %s to "%s".', count($eligible), $plural(count($eligible)), $areaName);

        if ($skipped > 0) {
            $message .= sprintf(' %d selected %s could not be changed (already deleted or protected).', $skipped, $plural($skipped));
        }

        return redirect()->route($indexRoute)->with('status', $message);
    }
}
