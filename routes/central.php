<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central Routes
|--------------------------------------------------------------------------
|
| Loaded once per central domain by routes/web.php. Split into per-concern
| files so parallel work doesn't collide on a single route file:
|   - routes/central-auth.php      platform_admins login/logout ("platform" guard)
|   - routes/central-tenants.php   tenant management CRUD (protected by auth:platform)
|
*/

Route::get('/', function (Request $request) {
    // Same pattern as routes/tenant.php's own root route: explicitly the
    // "platform" guard, never the ambiguous default. The stock
    // resources/views/welcome.blade.php this used to render links its
    // @auth block to the tenant-side /dashboard route (url('/dashboard')),
    // which 404s on the central domain - PreventAccessFromCentralDomains
    // blocks it there entirely. Redirecting instead of rendering a static
    // page sidesteps that stale link rather than patching it in place.
    return $request->user('platform')
        ? redirect()->route('central.dashboard')
        : redirect()->route('login');
});

require base_path('routes/central-auth.php');
require base_path('routes/central-tenants.php');
require base_path('routes/central-tenant-users.php');
require base_path('routes/central-tenant-settings.php');
require base_path('routes/central-activity-log.php');
require base_path('routes/central-settings.php');
require base_path('routes/central-platform-admins.php');
