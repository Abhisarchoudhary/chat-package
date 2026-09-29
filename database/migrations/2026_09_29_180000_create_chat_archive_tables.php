<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The permanent record of what was said.
 *
 * Stream carries the conversation and deletes its copy after thirty days to
 * keep the bill down, which makes this the only copy past that age — the
 * archive is not a convenience for the audit page, it is the record itself.
 *
 * Written from the webhook and repaired by a nightly sweep, in one portal.
 * Three writers would be three archives that disagree.
 *
 * **Every row records the Stream application it came from.** An application can
 * be replaced — the first one will be, once it has proved the design — and the
 * day it is, conversations from the old and the new are otherwise
 * indistinguishable in the same table.
 *
 * **A deleted message keeps its text.** Somebody removing their own message
 * hides it from the conversation; erasing it from the record is a super admin's
 * decision, with a permission, and it is a different act with a different name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_channels', function (Blueprint $table) {
            $table->id();
            $table->string('cid', 120)->unique();
            $table->string('type', 20);
            $table->string('channel_id', 80);
            $table->string('name')->nullable();
            $table->string('portal', 20)->nullable();
            $table->string('app', 40)->nullable();
            $table->string('created_by', 64)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->index(['type', 'last_message_at']);
            $table->index('portal');
        });

        Schema::create('chat_members', function (Blueprint $table) {
            $table->id();
            $table->string('cid', 120);
            $table->string('user_id', 64);
            $table->string('role', 20)->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();

            $table->unique(['cid', 'user_id']);
            // "Which conversations was this person in", for the audit page.
            $table->index('user_id');
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->string('stream_id', 80)->unique();
            $table->string('cid', 120);
            $table->string('user_id', 64)->nullable();
            $table->string('user_name')->nullable();
            $table->text('text')->nullable();
            $table->string('parent_id', 80)->nullable();
            $table->json('attachments')->nullable();
            $table->string('app', 40)->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            // Hidden from the conversation; the words stay here.
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_by', 64)->nullable();
            // Erased on purpose, by somebody with the permission.
            $table->timestamp('purged_at')->nullable();
            $table->string('purged_by', 64)->nullable();

            $table->timestamps();

            // The conversation, in order: what both the audit page and the
            // "older than Stream keeps" reader ask for.
            $table->index(['cid', 'sent_at']);
            $table->index(['user_id', 'sent_at']);
            // The sweep's question: what is the newest thing we hold here?
            $table->index(['cid', 'stream_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_members');
        Schema::dropIfExists('chat_channels');
    }
};
