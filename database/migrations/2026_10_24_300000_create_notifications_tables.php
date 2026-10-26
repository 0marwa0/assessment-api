<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// R12: in-app notifications (Laravel database channel), per-user email opt-out,
// manager nudges (also the one-per-item-per-day throttle) and a log of the
// due-soon / overdue reminders so the daily command sends each one only once.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('email_notifications')->default(true);
        });

        Schema::create('nudges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manager_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('item_type', 20); // roadmap | assessment
            $table->unsignedBigInteger('item_id');
            $table->text('message');
            $table->boolean('via_app')->default(true);
            $table->boolean('via_email')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'item_type', 'item_id', 'created_at']);
        });

        Schema::create('assignment_reminders', function (Blueprint $table): void {
            $table->id();
            $table->string('assignment_type', 20); // roadmap | assessment
            $table->unsignedBigInteger('assignment_id');
            $table->string('kind', 20); // due_soon | overdue
            $table->timestamp('sent_at');
            $table->unique(['assignment_type', 'assignment_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_reminders');
        Schema::dropIfExists('nudges');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('email_notifications');
        });
        Schema::dropIfExists('notifications');
    }
};
