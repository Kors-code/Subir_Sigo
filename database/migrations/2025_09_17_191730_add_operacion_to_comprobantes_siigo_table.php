<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddOperacionToComprobantesSiigoTable extends Migration
{
    public function up()
    {
        Schema::table('comprobantes_siigo', function (Blueprint $table) {
            $table->string('operacion')->nullable()->index()->after('id');
        });
    }

    public function down()
    {
        Schema::table('comprobantes_siigo', function (Blueprint $table) {
            $table->dropColumn('operacion');
        });
    }
}
