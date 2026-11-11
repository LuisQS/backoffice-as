<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            // Agregamos los campos después de 'montoDetraccion' (o al final si prefieres)
            $table->string('numero_constancia_detraccion', 50)->nullable()->after('montoDetraccion');
            $table->date('fecha_pago_detraccion')->nullable()->after('numero_constancia_detraccion');
        });
    }

    public function down(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->dropColumn(['numero_constancia_detraccion', 'fecha_pago_detraccion']);
        });
    }
};