<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSiigoTrackingToComprobantesSiigoTable extends Migration
{
    public function up()
    {
        Schema::table('comprobantes_siigo', function (Blueprint $table) {
            if (!Schema::hasColumn('comprobantes_siigo', 'operacion')) {
                $table->string('operacion')->nullable()->index()->after('id');
            }
            if (!Schema::hasColumn('comprobantes_siigo', 'usuario')) {
                $table->string('usuario')->nullable()->after('operacion');
            }
            if (!Schema::hasColumn('comprobantes_siigo', 'chunk_index')) {
                $table->integer('chunk_index')->nullable()->after('usuario');
            }
            if (!Schema::hasColumn('comprobantes_siigo', 'params')) {
                $table->json('params')->nullable()->after('chunk_index');
            }
            if (!Schema::hasColumn('comprobantes_siigo', 'data')) {
                $table->json('data')->nullable()->after('params');
            }
            if (!Schema::hasColumn('comprobantes_siigo', 'last_response')) {
                $table->longText('last_response')->nullable()->after('data');
            }
            if (!Schema::hasColumn('comprobantes_siigo', 'status')) {
                $table->string('status')->default('pending')->after('last_response');
            }
            if (!Schema::hasColumn('comprobantes_siigo', 'attempts')) {
                $table->integer('attempts')->default(0)->after('status');
            }
            $table->timestamp('processed_at')->nullable()->after('attempts');
        });
    }

    public function down()
    {
        Schema::table('comprobantes_siigo', function (Blueprint $table) {
            foreach (['processed_at','attempts','status','last_response','data','params','chunk_index','usuario','operacion'] as $col) {
                if (Schema::hasColumn('comprobantes_siigo', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
}
