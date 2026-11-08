<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Events reported by the apps (POST /api/v1/events) and by Core itself.
        Schema::create('app_events', function (Blueprint $table) {
            $table->id();
            $table->string('app_id', 32)->index();
            $table->string('type', 100);
            $table->string('severity', 16)->index();
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();
        });

        // Central notification channels, each with its own filter.
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 16);
            $table->text('config');
            $table->boolean('enabled')->default(true);
            $table->string('min_severity', 16)->default('warning');
            $table->json('apps')->nullable();
            $table->json('types')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // Volume backups of the apps (tar.gz under /opt/pk95/backups/<app>/).
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('app_id', 32)->index();
            $table->string('reason', 16);
            $table->string('status', 16);
            $table->json('files')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('version')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::table('installed_apps', function (Blueprint $table) {
            $table->string('update_policy', 16)->default('notify');
            $table->boolean('auto_backup')->default(true);
            $table->string('image_id')->nullable();
            $table->string('available_version')->nullable();
            $table->timestamp('update_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('installed_apps', function (Blueprint $table) {
            $table->dropColumn(['update_policy', 'auto_backup', 'image_id', 'available_version', 'update_checked_at']);
        });
        Schema::dropIfExists('backups');
        Schema::dropIfExists('notification_channels');
        Schema::dropIfExists('app_events');
    }
};
