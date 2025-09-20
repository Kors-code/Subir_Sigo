<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
{
    Schema::create('facturas', function (Blueprint $table) {
        $table->id();
        $table->string('folio'); // número de factura
        $table->decimal('importe', 12, 2)->default(0);
        $table->decimal('cop', 12, 2)->default(0);
        $table->integer('cantidad')->default(0);
        $table->string('estado')->default('Siigo');
        $table->json('detalles')->nullable(); // productos o items de la factura
        $table->string('pdf')->nullable();    // ruta al PDF generado
        $table->timestamps();
    });
}


    /**
     * Reverse the migrations.
     */
public function down(): void
{
    Schema::dropIfExists('facturas');
}

};
