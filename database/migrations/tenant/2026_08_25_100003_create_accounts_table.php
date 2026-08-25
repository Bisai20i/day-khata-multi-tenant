<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A leaf ledger account (the legacy app's "mainaccount") attaches to
     * either an account group or an account subgroup directly - some groups
     * (e.g. "Sales Accounts") have no subgroup level at all. Exactly one of
     * account_group_id/account_subgroup_id must be set; enforced in
     * App\Models\Account, not the database, to stay portable (see
     * mem.md "Stay portable" rule - no engine-specific CHECK constraints).
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_group_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('account_subgroup_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code')->nullable()->unique();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
