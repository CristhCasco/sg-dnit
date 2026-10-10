<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las empresas (persona JURIDICA) no tienen nombre, apellido ni CI de persona.
 * En las bases creadas con la migración original esas columnas son NOT NULL y
 * guardar una empresa falla. Solo se modifica lo que todavía no acepta NULL.
 * Se usa SQL directo porque los proyectos no tienen doctrine/dbal para ->change().
 */
class MakeCustomersIdentityNullable extends Migration
{
    private $columns = [
        'name' => 'VARCHAR(255) NULL',
        'last_name' => 'VARCHAR(255) NULL',
        'ci' => 'INT NULL',
    ];

    public function up()
    {
        foreach ($this->columns as $column => $definition) {
            $info = DB::selectOne(
                'SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                ['customers', $column]
            );

            if ($info && $info->IS_NULLABLE === 'NO') {
                DB::statement("ALTER TABLE customers MODIFY `{$column}` {$definition}");
            }
        }
    }

    public function down()
    {
        // No se revierte: volver a NOT NULL fallaría con las empresas ya registradas.
    }
}
