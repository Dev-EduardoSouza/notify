<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_pacing', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->unsignedInteger('sequencia')->default(0);
            $table->timestampTz('ultimo_envio')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_pacing');
    }
};
