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
use FacturaScripts\Core\DbUpdater;
use FacturaScripts\Core\Migrations;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Mod130Conf;
use FacturaScripts\Dinamic\Model\Partida;
use FacturaScripts\Dinamic\Model\Subcuenta;
use FacturaScripts\Plugins\Modelo130\Lib\Modelo130;
use FacturaScripts\Plugins\Modelo130\Lib\Modelo130Accounts;
use FacturaScripts\Plugins\Modelo130\Lib\Modelo130Config;
use FacturaScripts\Plugins\Modelo130\Migration\MigrateV5;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas de la migración de subcuentas_130 a mod130_conf.
 */
final class MigrateV5Test extends TestCase
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

        (new MigrateV5())->run();

        $this->assertFalse($this->db()->tableExists(self::LEGACY_TABLE));
        $this->assertSame(
            $this->normalizeRules($defaults),
            $this->currentRules()
        );
    }

    /**
     * Con solo las subcuentas por defecto de la v4 no hay personalización que
     * conservar: se elimina la tabla antigua y la configuración queda vacía
     * para que ensureDefaults() la rellene con los valores actuales.
     */
    public function testMigrationWithOnlyLegacyDefaults(): void
    {
        $this->clearConfig();
        $this->createLegacyTable();
        $this->insertLegacyRow('4730000000', 'deducible');
        $this->insertLegacyRow('6420000000', 'deducible');

        (new MigrateV5())->run();

        $this->assertFalse($this->db()->tableExists(self::LEGACY_TABLE));
        $this->assertSame(0, (new Mod130Conf())->count());

        Modelo130Config::ensureDefaults();
        $this->assertNotEmpty((new Mod130Conf())->all([], [], 0, 0));
    }

    /**
     * Con configuración personalizada, se migran los prefijos conservando 60/70
     * y excluyendo la cuenta 473 del core.
     */
    public function testMigrationWithCustomLegacyConfig(): void
    {
        if ($this->db()->tableExists(Mod130Conf::tableName())) {
            $this->db()->exec('DROP TABLE ' . Mod130Conf::tableName());
        }
        DbUpdater::rebuild();

        $this->createLegacyTable();
        $this->insertLegacyRow('4730000000', 'deducible');
        $this->insertLegacyRow('6420000000', 'deducible');
        $this->insertLegacyRow('7550000000', 'ingreso');

        (new MigrateV5())->run();

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
     * Los asientos de liquidación de versiones anteriores se renombran y pasan
     * a llevar el identificador del trimestre en el campo documento.
     */
    public function testMigrationUpdatesAsientoConcept(): void
    {
        $asiento = $this->createLegacyPaymentEntry(true);

        (new MigrateV5())->run();

        $updated = new Asiento();
        $this->assertTrue($updated->load($asiento->idasiento));
        $this->assertSame('Pago fraccionado IRPF T1', $updated->concepto);
        $this->assertSame(Modelo130::entryDocument('T1'), $updated->documento);

        $this->assertTrue($updated->delete());
    }

    /**
     * Un asiento con el mismo concepto pero sin ninguna partida en la cuenta de
     * retenciones no pertenece al plugin y no debe modificarse.
     */
    public function testMigrationIgnoresUnrelatedEntries(): void
    {
        $asiento = $this->createLegacyPaymentEntry(false);

        (new MigrateV5())->run();

        $updated = new Asiento();
        $this->assertTrue($updated->load($asiento->idasiento));
        $this->assertSame(
            'Regularización de IRPF T1',
            $updated->concepto,
            'No debe tocarse un asiento ajeno al plugin'
        );
        $this->assertEmpty($updated->documento);

        $this->assertTrue($updated->delete());
    }

    /**
     * La migración solo debe ejecutarse una vez: al lanzarla por el registro de
     * migraciones, la segunda llamada no vuelve a tocar nada.
     */
    public function testMigrationRunsOnlyOnce(): void
    {
        $this->forgetMigration();

        $this->createLegacyTable();
        $this->insertLegacyRow('6210000000', 'deducible');

        Migrations::runPluginMigration(new MigrateV5());
        $this->assertFalse($this->db()->tableExists(self::LEGACY_TABLE));

        // volvemos a crear la tabla antigua: la migración ya no debe correr
        $this->createLegacyTable();
        $this->insertLegacyRow('6220000000', 'deducible');

        Migrations::runPluginMigration(new MigrateV5());
        $this->assertTrue(
            $this->db()->tableExists(self::LEGACY_TABLE),
            'La migración no debe repetirse una segunda vez'
        );
    }

    /**
     * Una subcuenta legacy genérica se convierte en un prefijo demasiado amplio
     * (6 o 7), así que debe descartarse y dejar solo los prefijos generales.
     */
    public function testMigrationDiscardsTooWidePrefixes(): void
    {
        if ($this->db()->tableExists(Mod130Conf::tableName())) {
            $this->db()->exec('DROP TABLE ' . Mod130Conf::tableName());
        }
        DbUpdater::rebuild();

        $this->createLegacyTable();
        $this->insertLegacyRow('6000000000', 'deducible');
        $this->insertLegacyRow('7000000000', 'ingreso');
        $this->insertLegacyRow('6420000000', 'deducible');
        $this->insertLegacyRow('4730000000', 'deducible');
        $this->insertLegacyRow('6210000000', 'desconocido');

        (new MigrateV5())->run();

        $this->assertSame(
            [
                Mod130Conf::TIPO_GASTO => ['60', '642'],
                Mod130Conf::TIPO_INGRESO => ['70'],
            ],
            $this->currentRules()
        );
    }

    /**
     * Elimina la marca de migración ejecutada del registro de MyFiles, para que
     * la prueba no dependa de ejecuciones anteriores.
     */
    private function forgetMigration(): void
    {
        $file = Tools::folder('MyFiles', 'migrations.json');

        if (false === file_exists($file)) {
            return;
        }

        $executed = json_decode((string)file_get_contents($file), true);

        if (false === is_array($executed)) {
            return;
        }

        $name = MigrateV5::getFullMigrationName();
        $executed = array_values(array_filter(
            $executed,
            function ($item) use ($name) {
                return $item !== $name;
            }
        ));

        file_put_contents($file, json_encode($executed, JSON_PRETTY_PRINT));
    }

    /**
     * Crea un asiento con el concepto antiguo del plugin, con o sin partida en
     * la cuenta de retenciones.
     */
    private function createLegacyPaymentEntry(bool $withWithholding): Asiento
    {
        $ejercicios = (new Ejercicio())->all([], ['codejercicio' => 'DESC'], 0, 1);
        $this->assertNotEmpty($ejercicios);

        $ejercicio = $ejercicios[0];

        $asiento = new Asiento();
        $asiento->idempresa = $ejercicio->idempresa;
        $asiento->codejercicio = $ejercicio->codejercicio;
        $asiento->concepto = 'Regularización de IRPF T1';
        $asiento->fecha = $ejercicio->fechainicio;
        $asiento->importe = 100.0;

        $this->assertTrue($asiento->save());

        if (false === $withWithholding) {
            return $asiento;
        }

        $subcuenta = new Subcuenta();
        $found = $subcuenta->loadWhere([
            Where::eq('codejercicio', $ejercicio->codejercicio),
            Where::like('codsubcuenta', Modelo130Accounts::WITHHOLDING_ACCOUNT . '%'),
        ]);

        if (false === $found) {
            $this->markTestSkipped('El plan contable no tiene la cuenta de retenciones.');
        }

        $partida = new Partida();
        $partida->idasiento = $asiento->idasiento;
        $partida->codsubcuenta = $subcuenta->codsubcuenta;
        $partida->concepto = $asiento->concepto;
        $partida->debe = 100.0;

        $this->assertTrue($partida->save());

        return $asiento;
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
