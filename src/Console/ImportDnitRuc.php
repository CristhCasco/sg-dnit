<?php

namespace CristhCasco\SgDnit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * Descarga el listado público de contribuyentes de la DNIT (ruc0.zip … ruc9.zip)
 * y lo carga en la tabla local dnit_contribuyentes.
 *
 * Los datos se cargan en una tabla temporal y solo reemplazan a los actuales si
 * todos los archivos se importaron bien, así una descarga fallida no deja la tabla vacía.
 */
class ImportDnitRuc extends Command
{
    protected $signature = 'dnit:importar
                            {--carpeta= : Importar desde una carpeta con los ruc0.zip … ruc9.zip ya descargados}';

    protected $description = 'Descarga e importa el listado oficial de RUC de la DNIT';

    const LISTADO_URL_DEFAULT = 'https://www.dnit.gov.py/web/portal-institucional/listado-de-ruc-con-sus-equivalencias';
    const BASE_URL = 'https://www.dnit.gov.py';
    const TABLA = 'dnit_contribuyentes';
    const TABLA_NUEVA = 'dnit_contribuyentes_nuevo';
    const LOTE = 1000;

    public function handle()
    {
        $carpeta = $this->option('carpeta');
        $trabajo = storage_path('app/dnit');
        File::ensureDirectoryExists($trabajo);

        try {
            $zips = $carpeta ? $this->zipsLocales($carpeta) : $this->descargar($trabajo);

            DB::statement('DROP TABLE IF EXISTS ' . self::TABLA_NUEVA);
            DB::statement('CREATE TABLE ' . self::TABLA_NUEVA . ' LIKE ' . self::TABLA);

            $total = 0;
            foreach ($zips as $zip) {
                $total += $this->importarZip($zip, $trabajo);
            }

            if ($total < 100000) {
                throw new \RuntimeException("Solo se leyeron {$total} registros, el listado parece incompleto.");
            }

            DB::statement('DROP TABLE IF EXISTS ' . self::TABLA . '_viejo');
            DB::statement('RENAME TABLE ' . self::TABLA . ' TO ' . self::TABLA . '_viejo, '
                . self::TABLA_NUEVA . ' TO ' . self::TABLA);
            DB::statement('DROP TABLE ' . self::TABLA . '_viejo');

            $this->info('Listado actualizado: ' . number_format($total, 0, ',', '.') . ' contribuyentes.');
            return 0;
        } catch (\Throwable $e) {
            DB::statement('DROP TABLE IF EXISTS ' . self::TABLA_NUEVA);
            $this->error('No se pudo importar el listado: ' . $e->getMessage());
            $this->line('Los datos anteriores no se modificaron.');
            return 1;
        } finally {
            if (!$carpeta) {
                File::deleteDirectory($trabajo);
            }
        }
    }

    /**
     * @return string[] rutas de los zip descargados
     */
    private function descargar(string $trabajo): array
    {
        $this->line('Buscando enlaces en el sitio de la DNIT...');
        $html = Http::timeout(60)->get(config('sg-dnit.listado_url', self::LISTADO_URL_DEFAULT))->throw()->body();

        preg_match_all('#href="([^"]*/ruc(\d)\.zip[^"]*)"#i', $html, $m, PREG_SET_ORDER);
        $links = [];
        foreach ($m as $match) {
            $links[$match[2]] = html_entity_decode($match[1]);
        }
        ksort($links);

        if (count($links) !== 10) {
            throw new \RuntimeException('Se esperaban 10 archivos (ruc0 a ruc9) y se encontraron ' . count($links) . '. Puede que la DNIT haya cambiado su página.');
        }

        $zips = [];
        foreach ($links as $n => $link) {
            $url = strpos($link, 'http') === 0 ? $link : self::BASE_URL . $link;
            $destino = $trabajo . "/ruc{$n}.zip";
            $this->line("Descargando ruc{$n}.zip...");
            Http::timeout(300)->withOptions(['sink' => $destino])->get($url)->throw();
            $zips[] = $destino;
        }

        return $zips;
    }

    private function zipsLocales(string $carpeta): array
    {
        $zips = [];
        for ($n = 0; $n <= 9; $n++) {
            $ruta = rtrim($carpeta, '/\\') . "/ruc{$n}.zip";
            if (!is_file($ruta)) {
                throw new \RuntimeException("Falta el archivo {$ruta}");
            }
            $zips[] = $ruta;
        }
        return $zips;
    }

    private function importarZip(string $zipPath, string $trabajo): int
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('No se pudo abrir ' . basename($zipPath));
        }

        $destino = $trabajo . '/' . pathinfo($zipPath, PATHINFO_FILENAME);
        File::ensureDirectoryExists($destino);
        $zip->extractTo($destino);
        $zip->close();

        $leidos = 0;
        foreach (File::glob($destino . '/*.txt') as $txt) {
            $leidos += $this->importarTxt($txt);
        }
        File::deleteDirectory($destino);

        if ($leidos === 0) {
            throw new \RuntimeException(basename($zipPath) . ' no contiene registros.');
        }

        $this->line('  ' . basename($zipPath) . ': ' . number_format($leidos, 0, ',', '.') . ' registros');
        return $leidos;
    }

    private function importarTxt(string $txt): int
    {
        $handle = fopen($txt, 'r');
        $lote = [];
        $leidos = 0;

        while (($linea = fgets($handle)) !== false) {
            $linea = rtrim($linea, "\r\n");
            if (!mb_check_encoding($linea, 'UTF-8')) {
                $linea = mb_convert_encoding($linea, 'UTF-8', 'ISO-8859-1');
            }

            // RUC|RAZON SOCIAL|DV|RUC ANTERIOR|ESTADO|
            $p = explode('|', $linea);
            if (count($p) < 5 || trim($p[0]) === '' || strlen(trim($p[2])) !== 1) {
                continue;
            }

            $lote[] = [
                'ruc' => mb_substr(trim($p[0]), 0, 20),
                'dv' => trim($p[2]),
                'razon_social' => mb_substr(trim($p[1]), 0, 255),
                'estado' => mb_substr(trim($p[4]), 0, 30),
            ];
            $leidos++;

            if (count($lote) >= self::LOTE) {
                DB::table(self::TABLA_NUEVA)->insertOrIgnore($lote);
                $lote = [];
            }
        }
        fclose($handle);

        if ($lote) {
            DB::table(self::TABLA_NUEVA)->insertOrIgnore($lote);
        }

        return $leidos;
    }
}
