<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A notice is a dashboard-only announcement, not fiscal-year-scoped and
     * never touches the ledger. starts_at/ends_at are nullable on purpose -
     * a null starts_at means "active immediately", a null ends_at means
     * "never expires". is_active is a separate admin off-switch so a
     * notice can be hidden outright without deleting it or fiddling with
     * its date window.
     */
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
