<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_emails', function (Blueprint $table) {
            $table->id();
            // Sender mailbox address. Sending authenticates through Dovecot
            // master-user auth, so no mailbox password is ever stored.
            $table->string('mailbox_email')->index();
            $table->string('to');
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('subject');
            $table->longText('body');
            $table->string('in_reply_to')->nullable();
            // [{name, contentType, content(base64)}]
            $table->longText('attachments')->nullable();
            $table->timestamp('send_at')->index();
            // pending -> sending -> sent | failed
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_emails');
    }
};
