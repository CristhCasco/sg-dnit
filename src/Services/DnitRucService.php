<?php

namespace CristhCasco\SgDnit\Services;

use Illuminate\Support\Facades\DB;

/**
 * Consulta de contribuyentes contra la copia local del listado de la DNIT
 * y cálculo del dígito verificador (módulo 11, algoritmo oficial).
 */
class DnitRucService
{
    /**
     * Dígito verificador oficial (módulo 11, base máxima 11).
     */
    public static function calcularDv(string $numero): ?int
    {
        $numero = trim($numero);
        if ($numero === '' || !ctype_digit($numero)) {
            return null;
        }

        $k = 2;
        $total = 0;
        for ($i = strlen($numero) - 1; $i >= 0; $i--) {
            if ($k > 11) {
                $k = 2;
            }
            $total += ((int) $numero[$i]) * $k;
            $k++;
        }

        $resto = $total % 11;
        return $resto > 1 ? 11 - $resto : 0;
    }

    /**
     * Busca un contribuyente por su número de RUC/CI (sin DV).
     *
     * @return array|null ['ruc', 'dv', 'estado', 'tipo' => FISICA|JURIDICA, 'nombre', 'apellido', 'razon_social']
     */
    public function buscar(string $numero): ?array
    {
        $numero = trim($numero);
        if ($numero === '') {
            return null;
        }

        $row = DB::table('dnit_contribuyentes')->where('ruc', $numero)->first();
        if (!$row) {
            return null;
        }

        $razon = trim(preg_replace('/\s+/', ' ', $row->razon_social));
        $esEmpresa = $this->esPersonaJuridica($numero, $razon);

        $nombre = null;
        $apellido = null;
        if (!$esEmpresa) {
            // Formato DNIT para personas: "APELLIDOS, NOMBRES"
            $partes = array_map('trim', explode(',', $razon, 2));
            $apellido = $partes[0] ?: null;
            $nombre = $partes[1] ?? null;
        }

        return [
            'ruc' => $row->ruc,
            'dv' => $row->dv,
            'estado' => $row->estado,
            'tipo' => $esEmpresa ? 'JURIDICA' : 'FISICA',
            'nombre' => $nombre,
            'apellido' => $apellido,
            'razon_social' => $razon,
        ];
    }

    public function hayDatos(): bool
    {
        return DB::table('dnit_contribuyentes')->exists();
    }

    /**
     * Los RUC de personas jurídicas los asigna la DNIT a partir de 80.000.000
     * y sus nombres no llevan el formato "APELLIDOS, NOMBRES".
     */
    private function esPersonaJuridica(string $numero, string $razon): bool
    {
        if (ctype_digit($numero) && (int) $numero >= 80000000) {
            return true;
        }

        return strpos($razon, ',') === false
            && preg_match('/\b(S\.?A\.?|S\.?R\.?L\.?|S\.?A\.?C\.?I\.?|S\.?A\.?E\.?C\.?A\.?|E\.?A\.?S\.?|SOCIEDAD|ASOCIACION|COOPERATIVA|FUNDACION)\b/u', $razon) === 1;
    }
}
