<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tenant/Sales/Agents/Index', [
            'agents' => Agent::query()->with('account:id,code')->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Agent::create($this->validated($request));

        return redirect()->route('tenant.agents.index')->with('status', 'Agent added.');
    }

    public function update(Request $request, Agent $agent): RedirectResponse
    {
        $agent->update($this->validated($request));

        return redirect()->route('tenant.agents.index')->with('status', 'Agent updated.');
    }

    public function destroy(Agent $agent): RedirectResponse
    {
        $agent->delete();

        return redirect()->route('tenant.agents.index')->with('status', 'Agent deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile_no' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
        ]);
    }
}
