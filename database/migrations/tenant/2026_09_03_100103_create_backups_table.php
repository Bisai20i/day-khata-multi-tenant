<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per on-demand database backup for this tenant. The actual
     * file never lives under public/ (see routes/tenant-backups.php's
     * docblock) - it's stored on the private "local" disk under
     * backups/{tenant_id}/{filename}, with only the basename kept here.
     */
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('disk')->default('local');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('status')->default('completed');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
