<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Task 29 follow-up: system-generated messages (exemption granted, special access granted) render
// as a KTUI alert in the thread instead of a plain chat bubble. NULL = an ordinary typed message.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tbl_conversation_messages', 'kind')) {
            return;
        }

        Schema::table('tbl_conversation_messages', function (Blueprint $table): void {
            $table->string('kind')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_conversation_messages', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
