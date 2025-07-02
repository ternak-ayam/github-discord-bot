<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_checkins', function (Blueprint $table) {
            $table->id();
            $table->string('discord_user_id');
            $table->string('username');
            $table->timestamp('checkin_at');
            $table->timestamp('checkout_at')->nullable();
            $table->text('work_notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('discord_user_id');
            $table->index('checkin_at');
            $table->index(['discord_user_id', 'checkin_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_checkins');
    }
};
