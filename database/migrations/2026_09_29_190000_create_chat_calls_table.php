<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The calls people make from chat.
 *
 * Recorded, like the phone calls: the recording arrives from Stream when the
 * call ends, and hearing one is a permission that starts with nobody. The
 * transcript follows the same path the RingCentral recordings take, so a
 * conversation held in chat is as readable afterwards as one held on the phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_calls', function (Blueprint $table) {
            $table->id();
            $table->string('call_cid', 120)->unique();
            $table->string('cid', 120)->nullable();
            $table->string('kind', 20)->default('audio');
            $table->string('started_by', 64)->nullable();
            $table->json('participants')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);

            $table->string('recording_url', 1000)->nullable();
            $table->string('recording_path')->nullable();
            $table->string('recording_disk', 20)->nullable();
            $table->longText('transcript')->nullable();
            $table->json('transcript_segments')->nullable();
            $table->text('summary')->nullable();
            $table->string('transcript_source', 60)->nullable();
            $table->timestamp('transcribed_at')->nullable();
            $table->timestamp('transcribe_sent_at')->nullable();
            $table->string('transcribe_error')->nullable();

            $table->string('app', 40)->nullable();
            $table->timestamps();

            $table->index(['cid', 'started_at']);
            $table->index(['started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_calls');
    }
};
