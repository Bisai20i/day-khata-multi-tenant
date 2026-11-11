<?php

namespace App\Http\Controllers\Tenant\Parties;

use App\Http\Controllers\Controller;
use App\Models\Area;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master list of areas that customers and suppliers can be assigned to.
 * Gated per route: `areas.view` to open the page, `areas.manage` to change it.
 */
class AreaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Parties/Areas/Index', [
            'areas' => Area::query()
                ->withCount(['customers', 'suppliers'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * A `quick_add` request comes from the "+ New area" shortcut on the
     * customer / supplier forms and report filters, so it returns there
     * instead of landing on the Areas page.
     */
    public function store(Request $request): RedirectResponse
    {
        $name = $this->validatedName($request);

        try {
            $area = Area::create(['name' => $name]);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with another request that inserted the same name.
            throw ValidationException::withMessages(['name' => 'An area with this name already exists.']);
        }

        $redirect = $request->boolean('quick_add') ? back() : redirect()->route('tenant.areas.index');

        return $redirect->with('status', "Area \"{$area->name}\" added.");
    }

    public function update(Request $request, Area $area): RedirectResponse
    {
        $name = $this->validatedName($request, $area);

        try {
            $area->update(['name' => $name]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'An area with this name already exists.']);
        }

        return redirect()->route('tenant.areas.index')->with('status', "Area renamed to \"{$area->name}\".");
    }

    public function destroy(Area $area): RedirectResponse
    {
        $customers = $area->customers()->count();
        $suppliers = $area->suppliers()->count();

        if ($customers + $suppliers > 0) {
            return back()->withErrors([
                'area' => "Cannot delete \"{$area->name}\": {$this->usageSentence($customers, $suppliers)} assigned to it. Move them to another area first.",
            ]);
        }

        try {
            $area->delete();
        } catch (QueryException) {
            // A party was assigned between the count above and the delete; the foreign key refused it.
            return back()->withErrors(['area' => "Cannot delete \"{$area->name}\": it was just assigned to a customer or supplier. Move them to another area first."]);
        }

        return redirect()->route('tenant.areas.index')->with('status', "Area \"{$area->name}\" deleted.");
    }

    /**
     * Whitespace-normalised name, validated for presence, length and
     * case-insensitive uniqueness ("pokhara" and "Pokhara " are one area).
     */
    private function validatedName(Request $request, ?Area $ignore = null): string
    {
        $raw = $request->input('name');
        $name = is_string($raw) ? Area::normalizeName($raw) : '';

        $request->merge(['name' => $name]);
        $request->validate([
            'name' => [
                'required',
                'string',
                'max:'.Area::NAME_MAX_LENGTH,
                function (string $attribute, mixed $value, \Closure $fail) use ($ignore): void {
                    $duplicate = Area::query()
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)])
                        ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
                        ->exists();

                    if ($duplicate) {
                        $fail('An area with this name already exists.');
                    }
                },
            ],
        ]);

        return $name;
    }

    /**
     * "2 customers and 1 supplier are" / "1 customer is" - the subject and verb
     * of the delete-refusal message.
     */
    private function usageSentence(int $customers, int $suppliers): string
    {
        $parts = [];

        if ($customers > 0) {
            $parts[] = $customers === 1 ? '1 customer' : "{$customers} customers";
        }

        if ($suppliers > 0) {
            $parts[] = $suppliers === 1 ? '1 supplier' : "{$suppliers} suppliers";
        }

        return implode(' and ', $parts).($customers + $suppliers === 1 ? ' is' : ' are');
    }
}
