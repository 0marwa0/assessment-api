<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Role::whereIn('name', array_map(fn (UserRole $role): string => $role->value, UserRole::cases()))->delete();
    }
};
