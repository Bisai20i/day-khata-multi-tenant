<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One saved line of boilerplate note text a cashier can drop into a sale's
 * narration on Sales/Create.vue (audit section 4 polish, "note templates").
 * Deliberately tiny: no per-user scoping, no ordering column, just a shared,
 * tenant-wide list a handful of rows long.
 */
#[Fillable(['text'])]
class SaleNoteTemplate extends Model {}
