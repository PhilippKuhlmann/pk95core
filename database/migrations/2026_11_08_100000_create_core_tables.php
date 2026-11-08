<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installed_apps', function (Blueprint $table) {
            $table->id();
            $table->string('app_id', 32)->unique();
            $table->string('status', 16)->index();
            $table->string('tag')->default('latest');
            $table->string('container_id')->nullable();
            $table->text('token');
            $table->json('manifest')->nullable();
            $table->string('version')->nullable();
            $table->string('step')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('app_logs', function (Blueprint $table) {
            $table->id();
            $table->string('app_id', 32);
            $table->string('level', 16)->default('info');
            $table->text('message');
            $table->timestamp('created_at')->nullable();
            $table->index(['app_id', 'id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('app_logs');
        Schema::dropIfExists('installed_apps');
    }
};
