<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the branches table and inserts the one Main branch every tenant
     * must have. The insert is guarded so a re-run never adds a second Main.
     * The literal name and code are frozen here on purpose (no config reads).
     */
    public function up(): void
    {
        if (! Schema::hasTable('branches')) {
            Schema::create('branches', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique('branches_name_unique');
                $table->string('code', 10)->unique('branches_code_unique');
                $table->string('series_code', 6)->nullable()->unique('branches_series_code_unique');
                $table->string('address')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->string('pan_number')->nullable();
                $table->foreignId('default_store_id')->nullable()->constrained('stores')->nullOnDelete();
                $table->boolean('is_main')->default(false);
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! DB::table('branches')->where('is_main', true)->exists()) {
            DB::table('branches')->insert([
                'name' => 'Main',
                'code' => 'MAIN',
                'is_main' => true,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
