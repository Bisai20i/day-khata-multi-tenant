<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central Routes
|--------------------------------------------------------------------------
|
| Every domain configured in config/tenancy.php `central_domains` gets its
| own Route::domain() group so central routes are never matched when a
| request arrives on a tenant subdomain (tenant requests are handled
| entirely by routes/tenant.php). See routes/central.php for the actual
| route definitions.
|
*/

// Route names must be unique for `route:cache`, so only the first central
// domain keeps the names; routes registered for the remaining domains are
// matched by URI only.
foreach (array_values(config('tenancy.central_domains')) as $index => $domain) {
    $registeredBefore = count(Route::getRoutes()->getRoutes());

    Route::domain($domain)->group(base_path('routes/central.php'));

    if ($index === 0) {
        continue;
    }

    foreach (array_slice(Route::getRoutes()->getRoutes(), $registeredBefore) as $route) {
        $route->setAction(array_diff_key($route->getAction(), ['as' => true]));
    }

    Route::getRoutes()->refreshNameLookups();
}
