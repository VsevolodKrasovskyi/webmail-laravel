<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A user managing a whole domain: its mailboxes and aliases, and the domain itself.
        Schema::create('domain_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'domain_id']);
        });

        // A user with access to single mailboxes only.
        Schema::create('mailbox_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'mailbox_id']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            // SHA-256 of the token from the e-mailed link, the token itself is never stored.
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            // {"domains": [id, ...], "mailboxes": [id, ...]}
            $table->json('access');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('mailbox_user');
        Schema::dropIfExists('domain_user');
    }
};
