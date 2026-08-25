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

foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->group(base_path('routes/central.php'));
}
