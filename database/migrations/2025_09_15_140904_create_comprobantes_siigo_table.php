<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateComprobantesSiigoTable extends Migration
{
    public function up()
    {
        Schema::create('comprobantes_siigo', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('operacion')->nullable();
            $table->json('data')->nullable();
            $table->string('usuario')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('comprobantes_siigo');
    }
}
