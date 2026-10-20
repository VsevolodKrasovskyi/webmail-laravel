<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            // Full source address (user@domain.tld) or catch-all (@domain.tld).
            $table->string('source');
            // Destination mailbox address this alias forwards to.
            $table->string('destination');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['source', 'destination']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aliases');
    }
};
