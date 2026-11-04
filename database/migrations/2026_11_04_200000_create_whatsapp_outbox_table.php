<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('api_key_name', 64);
            $table->string('recipient', 64);
            $table->text('text')->nullable();
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at');
            $table->string('last_error', 255)->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('sent_at')->nullable();
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_outbox');
    }
};
