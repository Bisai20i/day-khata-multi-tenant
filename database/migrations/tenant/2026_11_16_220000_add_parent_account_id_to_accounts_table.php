<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('accounts', 'parent_account_id')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->foreignId('parent_account_id')
                    ->nullable()
                    ->after('address')
                    ->constrained('accounts')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('accounts', 'parent_account_id')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('parent_account_id');
            });
        }
    }
};
