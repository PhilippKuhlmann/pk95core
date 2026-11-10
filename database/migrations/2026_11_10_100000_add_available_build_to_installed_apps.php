<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Newer build of a moving tag without a released version: {revision, created} from the image labels.
        Schema::table('installed_apps', function (Blueprint $table) {
            $table->json('available_build')->nullable()->after('available_version');
        });
    }

    public function down(): void
    {
        Schema::table('installed_apps', function (Blueprint $table) {
            $table->dropColumn('available_build');
        });
    }
};
