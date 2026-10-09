<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copia local del listado público de contribuyentes de la DNIT (ex SET).
 * Se llena y actualiza con: php artisan dnit:importar
 */
class CreateDnitContribuyentesTable extends Migration
{
    public function up()
    {
        Schema::create('dnit_contribuyentes', function (Blueprint $table) {
            $table->string('ruc', 20)->primary();
            $table->char('dv', 1);
            $table->string('razon_social', 255);
            $table->string('estado', 30);
        });
    }

    public function down()
    {
        Schema::dropIfExists('dnit_contribuyentes');
    }
}
