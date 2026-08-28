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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Dinamic\Model\Mod130Conf;
use FacturaScripts\Plugins\Modelo130\Lib\Modelo130Config;
use FacturaScripts\Plugins\Modelo130\Migration\MigrateSubcuentas130;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas de la migración de subcuentas_130 a mod130_conf.
 */
final class MigrateSubcuentas130Test extends TestCase
{
    use DefaultSettingsTrait;
    use LogErrorsTrait;

    private const LEGACY_TABLE = 'subcuentas_130';

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
        self::installAccountingPlan();
    }

    protected function tearDown(): void
    {
        $this->dropLegacyTable();
        $this->clearConfig();
        $this->logErrors();
    }

    /**
     * Si no existe la tabla antigua, la migración no debe alterar la configuración.
     */
    public function testMigrationDoesNothingWithoutLegacyTable(): void
    {
        $this->dropLegacyTable();
        $this->clearConfig();

        $defaults = Modelo130Config::buildDefaultRules();
        $this->seedConfig($defaults);

        (new MigrateSubcuentas130())->run();

        $this->assertFalse($this->db()->tableExists(self::LEGACY_TABLE));
        $this->assertSame(
            $this->normalizeRules($defaults),
            $this->currentRules()
        );
    }

    /**
     * Con solo las subcuentas por defecto de la v4, se elimina la tabla antigua
     * y se mantiene la configuración para que ensureDefaults la rellene.
     */
    public function testMigrationWithOnlyLegacyDefaults(): void
    {
        $this->clearConfig();
        $this->createLegacyTable();
        $this->insertLegacyRow('4730000000', 'deducible');
        $this->insertLegacyRow('6420000000', 'deducible');

        (new MigrateSubcuentas130())->run();

        $this->assertFalse($this->db()->tableExists(self::LEGACY_TABLE));
        $this->assertSame([], $this->currentRules());

        Modelo130Config::ensureDefaults();

        $this->assertNotEmpty((new Mod130Conf())->all([], [], 0, 0));
    }

    /**
     * Con configuración personalizada, se migran los prefijos conservando 60/70
     * y excluyendo la cuenta 473 del core.
     */
    public function testMigrationWithCustomLegacyConfig(): void
    {
        $this->clearConfig();
        $this->createLegacyTable();
        $this->insertLegacyRow('4730000000', 'deducible');
        $this->insertLegacyRow('6420000000', 'deducible');
        $this->insertLegacyRow('7550000000', 'ingreso');

        (new MigrateSubcuentas130())->run();

        $this->assertFalse($this->db()->tableExists(self::LEGACY_TABLE));
        $this->assertSame(
            [
                Mod130Conf::TIPO_GASTO => ['60', '642'],
                Mod130Conf::TIPO_INGRESO => ['70', '755'],
            ],
            $this->currentRules()
        );
    }

    /**
     * @param array<string, string[]> $rules
     */
    private function seedConfig(array $rules): void
    {
        foreach ($rules as $tipo => $codes) {
            foreach ($codes as $code) {
                $rule = new Mod130Conf();
                $rule->codigo = $code;
                $rule->tipo = $tipo;
                $this->assertTrue($rule->save());
            }
        }
    }

    private function clearConfig(): void
    {
        foreach ((new Mod130Conf())->all([], [], 0, 0) as $rule) {
            $this->assertTrue($rule->delete());
        }
    }

    private function createLegacyTable(): void
    {
        $this->dropLegacyTable();

        $sql = 'CREATE TABLE ' . self::LEGACY_TABLE . ' ('
            . 'id serial NOT NULL,'
            . 'codsubcuenta character varying(15) NOT NULL,'
            . 'tipo character varying(20) NOT NULL DEFAULT \'deducible\','
            . 'creation_date timestamp,'
            . 'nick character varying(50),'
            . 'last_nick character varying(50),'
            . 'last_update timestamp,'
            . 'name character varying(100),'
            . 'PRIMARY KEY (id),'
            . 'UNIQUE (codsubcuenta)'
            . ');';

        $this->assertTrue($this->db()->exec($sql));
    }

    private function dropLegacyTable(): void
    {
        if ($this->db()->tableExists(self::LEGACY_TABLE)) {
            $this->db()->exec('DROP TABLE ' . self::LEGACY_TABLE);
        }
    }

    private function insertLegacyRow(string $codsubcuenta, string $tipo): void
    {
        $sql = 'INSERT INTO ' . self::LEGACY_TABLE
            . ' (codsubcuenta, tipo) VALUES ('
            . $this->db()->var2str($codsubcuenta) . ', '
            . $this->db()->var2str($tipo) . ');';

        $this->assertTrue($this->db()->exec($sql));
    }

    /**
     * @return array<string, string[]>
     */
    private function currentRules(): array
    {
        $result = [
            Mod130Conf::TIPO_GASTO => [],
            Mod130Conf::TIPO_INGRESO => [],
        ];

        foreach ((new Mod130Conf())->all([], ['codigo' => 'ASC'], 0, 0) as $rule) {
            $result[(string) $rule->tipo][] = (string) $rule->codigo;
        }

        sort($result[Mod130Conf::TIPO_GASTO]);
        sort($result[Mod130Conf::TIPO_INGRESO]);

        return $result;
    }

    /**
     * @param array<string, string[]> $rules
     *
     * @return array<string, string[]>
     */
    private function normalizeRules(array $rules): array
    {
        $result = [
            Mod130Conf::TIPO_GASTO => $rules[Mod130Conf::TIPO_GASTO] ?? [],
            Mod130Conf::TIPO_INGRESO => $rules[Mod130Conf::TIPO_INGRESO] ?? [],
        ];

        sort($result[Mod130Conf::TIPO_GASTO]);
        sort($result[Mod130Conf::TIPO_INGRESO]);

        return $result;
    }

    private function db(): DataBase
    {
        static $db = null;

        if ($db === null) {
            $db = new DataBase();
            $db->connect();
        }

        return $db;
    }
}
