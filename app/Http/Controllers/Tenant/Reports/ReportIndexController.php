<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Reports hub: a landing page that lists every report the signed-in user
 * can open. It sends no data of its own - the catalog of reports lives in the
 * front end (resources/js/lib/report-catalog.js) and each entry is filtered by
 * the same permission key that gates the report's own route, so a link the
 * user cannot follow is never shown. The hub is therefore on the route-audit
 * allowlist (like the dashboard); every report behind it keeps its own `can:`.
 */
class ReportIndexController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Tenant/Reports/Index');
    }
}
