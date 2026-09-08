<?php
/**
 * This file is part of Modelo130 plugin for FacturaScripts
 * Copyright (C) 2026 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\Modelo130\Migration;

use FacturaScripts\Core\DbUpdater;
use FacturaScripts\Core\Template\MigrationClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Mod130Conf;
use RuntimeException;

/**
 * Migra la configuración de subcuentas de la versión 4 (subcuentas_130)
 * al nuevo formato de prefijos (mod130_conf) de la versión 5.
 * 
 * Asimismo, actualiza los conceptos de los asientos de regularización al nuevo formato para que su
 * categorización sea consistente con el nuevo controlador y se visualice la información más coherente.
 */
class MigrateV5 extends MigrationClass
{
    /**
     * Nombre completo registrado:
     * Modelo130::migrate_v5
     */
    const MIGRATION_NAME = 'migrate_v5';

    private const LEGACY_TABLE = 'subcuentas_130';

    /** @var array<string, string> */
    private const LEGACY_DEFAULTS = [
        '4730000000' => 'deducible',
        '6420000000' => 'deducible',
    ];

    private const LEGACY_TIPO_DEDUCIBLE = 'deducible';
    private const LEGACY_TIPO_INGRESO = 'ingreso';

    public function run(): void
    {
        $this->migrateAsientoConcepts();

        /**
         * Si no existe subcuentas_130, el usuario ya está en el sistema
         * nuevo o es una instalación nueva. No hacemos nada.
         */
        if (!$this->db()->tableExists(self::LEGACY_TABLE)) {
            return;
        }

        $rows = $this->db()->select(
            'SELECT codsubcuenta, tipo FROM ' . self::LEGACY_TABLE
        );

        /**
         * Si no había configuración o únicamente estaban los defaults
         * antiguos, no hay personalización que conservar.
         *
         * Eliminamos la tabla antigua y Modelo130Config::ensureDefaults()
         * cargará posteriormente los defaults actuales.
         */
        if (empty($rows) || $this->isOnlyDefaultConfig($rows)) {
            $this->dropLegacyTable();
            return;
        }

        /**
         * Existe configuración personalizada antigua en subcuentas_130:
         * creamos/comprobamos mod130_conf, la dejamos vacía y volcamos
         * la configuración antigua convertida al nuevo formato.
         */
        $this->ensureTargetTable();
        $this->clearCurrentConfig();
        $this->migrateCustomConfig($rows);

        /**
         * La tabla antigua solo se elimina cuando la migración
         * ha terminado correctamente.
         */
        $this->dropLegacyTable();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function isOnlyDefaultConfig(array $rows): bool
    {
        if (count($rows) !== count(self::LEGACY_DEFAULTS)) {
            return false;
        }

        $found = [];

        foreach ($rows as $row) {
            $code = trim((string) ($row['codsubcuenta'] ?? ''));
            $tipo = trim((string) ($row['tipo'] ?? ''));
            $found[$code] = $tipo;
        }


        $defaults = self::LEGACY_DEFAULTS;

        ksort($found);
        ksort($defaults);

        return $found === $defaults;
    }

    /**
     * Asegura que mod130_conf exista  antes de insertar.
     */
    private function ensureTargetTable(): void
    {
        if (!$this->db()->tableExists(Mod130Conf::tableName())) {
            /**
             * Limpiamos la caché de tablas comprobadas antes de
             * instanciar el modelo.
             */
            DbUpdater::rebuild();

            /**
             * El constructor del modelo crea/comprueba su tabla.
             */
            new Mod130Conf();
        }

        if (!$this->db()->tableExists(Mod130Conf::tableName())) {
            throw new RuntimeException(
                'No se pudo crear la tabla ' . Mod130Conf::tableName()
            );
        }
    }

    /**
     * Dejamos mod130_conf vacía antes de volcar la configuración legacy.
     *
     * Mientras exista subcuentas_130, la configuración legacy es la que debe priorizarse.
     */
    private function clearCurrentConfig(): void
    {
        foreach ((new Mod130Conf())->all([], [], 0, 0) as $rule) {
            if (!$rule->delete()) {
                throw new RuntimeException(
                    'No se pudo vaciar la tabla ' . Mod130Conf::tableName()
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function migrateCustomConfig(array $rows): void
    {
        /**
         * Toda configuración personalizada anterior, siempre
         * partía de los prefijos generales de gastos e ingresos.
         */
        $rules = [
            Mod130Conf::TIPO_GASTO => ['60'],
            Mod130Conf::TIPO_INGRESO => ['70'],
        ];

        foreach ($rows as $row) {
            $code = trim((string) ($row['codsubcuenta'] ?? ''));

            /**
             * La 473 ya se trata internamente y no debe convertirse
             * en una regla configurable.
             */
            if ($code === '' || str_starts_with($code, '473')) {
                continue;
            }

            $prefix = self::extractPrefix($code);
            $tipo = $this->mapLegacyType((string) ($row['tipo'] ?? ''));

            if (
                $tipo === null
                || !self::isValidPrefixForType($prefix, $tipo)
            ) {
                continue;
            }

            $rules[$tipo][] = $prefix;
        }

        foreach ($rules as $tipo => $codes) {
            foreach (array_values(array_unique($codes)) as $code) {
                $this->insertRule($code, $tipo);
            }
        }
    }

    private function insertRule(string $code, string $tipo): void
    {
        $sql = 'INSERT INTO ' . Mod130Conf::tableName()
            . ' (codigo, tipo, creation_date) VALUES ('
            . $this->db()->var2str($code) . ', '
            . $this->db()->var2str($tipo) . ', '
            . $this->db()->var2str(Tools::dateTime()) . ');';

        if (!$this->db()->exec($sql)) {
            throw new RuntimeException(
                'No se pudo migrar la regla '
                . $code
                . ' ('
                . $tipo
                . ')'
            );
        }
    }

    private function mapLegacyType(string $tipo): ?string
    {
        return match (trim($tipo)) {
            self::LEGACY_TIPO_DEDUCIBLE => Mod130Conf::TIPO_GASTO,
            self::LEGACY_TIPO_INGRESO => Mod130Conf::TIPO_INGRESO,
            default => null,
        };
    }

    private static function extractPrefix(string $codsubcuenta): string
    {
        $code = trim($codsubcuenta);
        $prefix = rtrim($code, '0');

        return $prefix === '' ? $code : $prefix;
    }

    private static function isValidPrefixForType(
        string $prefix,
        string $tipo
    ): bool {
        if ($tipo === Mod130Conf::TIPO_GASTO) {
            return str_starts_with($prefix, '6');
        }

        if ($tipo === Mod130Conf::TIPO_INGRESO) {
            return str_starts_with($prefix, '7');
        }

        return false;
    }

    /**
     * Modificamos los conceptos de los asientos (en español) para que sean consistentes con la nueva configuración.
     *
     * La misma se ha realizado para compatibilizarse con laS configuraciones por defecto de AsientosPredefinidos.
     */

    private function migrateAsientoConcepts(): void
    {
        if (!$this->db()->tableExists('asientos')) {
            return;
        }
    
        for ($trimestre = 1; $trimestre <= 4; $trimestre++) {
            $oldConcepto = 'Regularización de IRPF T' . $trimestre;
            $newConcepto = 'Pago fraccionado IRPF T' . $trimestre;
    
            $sql = 'UPDATE asientos SET concepto = '
                . $this->db()->var2str($newConcepto)
                . ' WHERE concepto = '
                . $this->db()->var2str($oldConcepto)
                . ';';
    
            if (!$this->db()->exec($sql)) {
                throw new RuntimeException(
                    'No se pudo actualizar el concepto del asiento '
                    . $oldConcepto
                );
            }
        }
    }

    private function dropLegacyTable(): void
    {
        if (!$this->db()->exec('DROP TABLE ' . self::LEGACY_TABLE)) {
            throw new RuntimeException(
                'No se pudo eliminar la tabla antigua '
                . self::LEGACY_TABLE
            );
        }
    }
}