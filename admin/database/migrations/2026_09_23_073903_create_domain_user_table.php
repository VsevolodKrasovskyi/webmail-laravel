<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A user managing a whole domain: its mailboxes and aliases, and the domain itself. */
    public function up(): void
    {
        Schema::create('domain_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'domain_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_user');
    }
};
