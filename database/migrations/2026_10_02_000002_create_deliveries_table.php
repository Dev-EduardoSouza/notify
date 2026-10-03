<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('client_ref', 64);
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->string('idempotency_key', 255)->nullable();
            $table->char('request_hash', 64);
            $table->string('channel', 32);
            $table->string('recipient', 64);
            $table->string('format', 16)->default('plain');
            $table->text('text')->nullable();
            $table->unsignedInteger('parts_total')->default(0);
            $table->unsignedInteger('delivered_parts')->default(0);
            $table->string('status', 16)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['client_ref', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
