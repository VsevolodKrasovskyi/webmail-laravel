<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email');
        });

        // Administrators used to be the addresses in ADMIN_EMAIL, an invited user always has
        // domain or mailbox rows. Carry the existing ones over: an address still listed in
        // ADMIN_EMAIL, and every user who has no domain or mailbox access (a former admin).
        $listed = array_filter(array_map(fn ($e) => strtolower(trim($e)), explode(',', (string) env('ADMIN_EMAIL', ''))));

        DB::table('users')->orderBy('id')->get(['id', 'email'])->each(function ($user) use ($listed) {
            $hasAccess = DB::table('domain_user')->where('user_id', $user->id)->exists()
                || DB::table('mailbox_user')->where('user_id', $user->id)->exists();

            if (in_array(strtolower($user->email), $listed, true) || ! $hasAccess) {
                DB::table('users')->where('id', $user->id)->update(['is_admin' => true]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
