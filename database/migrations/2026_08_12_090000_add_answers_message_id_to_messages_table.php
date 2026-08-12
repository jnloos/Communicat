<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The second half of an adjacency pair: which message this one answers.
 *
 * Until now a message only carried an *outgoing* pointer (`adjacency_partner` =
 * "I address X"). Whether a pair was ever closed could only be guessed by
 * "the next speaker happened to be the addressee" — which counts a turn that
 * never mentions the asker as a closure, and loses the obligation entirely as
 * soon as anything is said in between.
 *
 * With the incoming pointer a turn can do both: close one pair
 * (`answers_message_id`) and open another (`adjacency_partner`), and both ends
 * stay visible in the transcript and countable in the study metrics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('answers_message_id')
                ->nullable()
                ->after('adjacency_partner_id')
                ->constrained('messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('answers_message_id');
        });
    }
};
