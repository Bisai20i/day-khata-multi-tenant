<?php

namespace App\Http\Requests\Tenant\Admin;

/**
 * Same rules as a new branch (StoreBranchRequest ignores the edited branch in
 * every uniqueness rule and checks the default store against it). Main may
 * be renamed (some companies call it Head Office); it can never stop being
 * Main or be deactivated, which the Branch model enforces.
 */
class UpdateBranchRequest extends StoreBranchRequest {}
