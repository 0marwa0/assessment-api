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
        Schema::create('simulation_test_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('simulation_test_session_id')->constrained()->cascadeOnDelete();
            $table->string('role', 50);
            $table->text('content');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['simulation_test_session_id', 'position'], 'sim_test_messages_session_position_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('simulation_test_messages');
    }
};
