<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInvoiceLinesTable extends Migration
{
    public function up()
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('Folio')->index();
            $table->text('Detalle')->nullable(); // descripción del item
            $table->string('Clasi')->nullable(); // code
            $table->decimal('COP', 18, 2)->nullable();
            $table->integer('Cantidad')->nullable();
            $table->decimal('Importe', 18, 2)->nullable();
            $table->string('Hora')->nullable();
            $table->string('Day')->nullable();
            $table->string('Month')->nullable();
            $table->string('Year')->nullable();
            $table->string('Nombre_del_vend')->nullable();
            $table->json('Costumer')->nullable(); // se puede guardar JSON
            $table->decimal('TRM', 18, 2)->nullable();
            $table->string('Estado')->default('Siigo')->index();
            $table->string('Pdf')->nullable();
            $table->text('Resolucion')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('invoice_lines');
    }
}
