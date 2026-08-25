<?php

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

Route::get('/', function () {
    return view('welcome');
});

require base_path('routes/central-auth.php');
require base_path('routes/central-tenants.php');
