<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What Core created automatically for an app deployment: proxy host and monitors.
        Schema::create('app_links', function (Blueprint $table) {
            $table->id();
            $table->string('source_app', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('name');
            $table->string('hostname')->default('');
            $table->string('domain')->default('');
            $table->string('target')->default('');
            $table->unsignedBigInteger('proxy_host_id')->nullable();
            $table->json('monitor_ids')->nullable();
            $table->string('state', 20)->default('active');
            $table->text('message')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['source_app', 'source_id']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_links');
    }
};
