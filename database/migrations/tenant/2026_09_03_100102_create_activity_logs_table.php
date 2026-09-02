<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail written by ActivityLogObserver (registered in
     * AppServiceProvider::boot() against a fixed list of financial models -
     * see that file). No updated_at: nothing should ever update or delete a
     * row here, so created_at is the only timestamp that means anything.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('description')->nullable();
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
