<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The local DNS record in PK95 DNS (domain → LAN IP of the proxy) Core created for a deployment.
        Schema::table('app_links', function (Blueprint $table) {
            $table->unsignedBigInteger('dns_record_id')->nullable()->after('monitor_ids');
            $table->string('dns_answer', 64)->default('')->after('dns_record_id');
            $table->text('dns_message')->nullable()->after('dns_answer');
        });
    }

    public function down(): void
    {
        Schema::table('app_links', function (Blueprint $table) {
            $table->dropColumn(['dns_record_id', 'dns_answer', 'dns_message']);
        });
    }
};
