<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_jobs', function (Blueprint $table): void {
            $table->string('job_id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('status')->index();
            $table->json('params_json');
            $table->timestamp('canceled_at')->nullable();
            $table->json('error_json')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_jobs');
    }
};
