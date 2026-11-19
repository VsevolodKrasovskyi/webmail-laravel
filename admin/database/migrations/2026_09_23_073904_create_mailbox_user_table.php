<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A user with access to single mailboxes only. */
    public function up(): void
    {
        Schema::create('mailbox_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'mailbox_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_user');
    }
};
