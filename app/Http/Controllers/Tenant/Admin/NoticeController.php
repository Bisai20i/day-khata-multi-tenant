<?php

namespace App\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NoticeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Admin/Notices/Index', [
            'notices' => Notice::query()->with('creator:id,name')->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user('web')->id;

        Notice::create($data);

        return redirect()->route('tenant.notices.index')->with('status', 'Notice added.');
    }

    public function update(Request $request, Notice $notice): RedirectResponse
    {
        $notice->update($this->validated($request));

        return redirect()->route('tenant.notices.index')->with('status', 'Notice updated.');
    }

    public function destroy(Notice $notice): RedirectResponse
    {
        $notice->delete();

        return redirect()->route('tenant.notices.index')->with('status', 'Notice deleted.');
    }

    /**
     * ends_at's after_or_equal check is only meaningful when starts_at is
     * also given - Rule::when() applies it conditionally so a null/missing
     * starts_at never gets coerced into a date comparison.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => [
                'nullable',
                'date',
                Rule::when(
                    $request->filled('starts_at'),
                    ['after_or_equal:starts_at'],
                ),
            ],
            'is_active' => ['boolean'],
        ]);
    }
}
