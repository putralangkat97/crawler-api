<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_results', function (Blueprint $table): void {
            $table->id();
            $table->string('job_id')->index();
            $table->text('url');
            $table->text('normalized_url');
            $table->string('url_hash', 64);
            $table->text('final_url')->nullable();
            $table->integer('status_code')->nullable();
            $table->string('content_type')->nullable();
            $table->string('request_used')->nullable();
            $table->string('source_type')->nullable();
            $table->text('content_r2_key')->nullable();
            $table->bigInteger('content_size')->nullable();
            $table->text('bytes_r2_key')->nullable();
            $table->bigInteger('bytes_size')->nullable();
            $table->string('bytes_sha256', 64)->nullable();
            $table->json('metadata_json')->nullable();
            $table->json('links_json')->nullable();
            $table->json('images_json')->nullable();
            $table->json('timing_json')->nullable();
            $table->boolean('success')->default(false);
            $table->json('error_json')->nullable();
            $table->timestamps();

            $table->index(['job_id', 'created_at']);
            $table->unique(['job_id', 'url_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_results');
    }
};
