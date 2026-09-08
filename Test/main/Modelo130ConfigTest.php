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
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Cuenta;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Mod130Conf;
use FacturaScripts\Plugins\Modelo130\Lib\Modelo130Accounts;
use FacturaScripts\Plugins\Modelo130\Lib\Modelo130Config;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas de la configuración de cuentas del Modelo 130.
 */
final class Modelo130ConfigTest extends TestCase
{
    use DefaultSettingsTrait;
    use LogErrorsTrait;

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
        self::installAccountingPlan();
    }

    /**
     * Los valores predeterminados solo deben incluir prefijos que existan en el
     * plan contable instalado, ya sea como cuenta exacta o como prefijo de
     * cuentas hijas, y con el dígito inicial propio de su tipo.
     */
    public function testDefaultRulesOnlyUseExistingAccounts(): void
    {
        $defaults = Modelo130Config::buildDefaultRules();

        $this->assertArrayHasKey(Mod130Conf::TIPO_GASTO, $defaults);
        $this->assertArrayHasKey(Mod130Conf::TIPO_INGRESO, $defaults);
        $this->assertNotEmpty($defaults[Mod130Conf::TIPO_GASTO]);
        $this->assertNotEmpty($defaults[Mod130Conf::TIPO_INGRESO]);

        foreach ($defaults as $type => $codes) {
            $firstDigit = $type === Mod130Conf::TIPO_GASTO ? '6' : '7';

            foreach ($codes as $code) {
                $this->assertStringStartsWith($firstDigit, $code);

                $account = new Cuenta();

                if ($account->loadWhere([Where::eq('codcuenta', $code)])) {
                    // hay cuenta exacta: se muestra su descripción real
                    $this->assertSame(
                        (string) $account->descripcion,
                        Modelo130Config::description($code),
                        "La descripción de {$code} debe proceder del plan contable"
                    );
                    continue;
                }

                // sin cuenta exacta debe existir al menos una cuenta hija
                $this->assertNotSame(
                    Tools::trans('model-130-prefix-not-found', ['%code%' => $code]),
                    Modelo130Config::description($code),
                    "El prefijo predeterminado {$code} no existe en el plan contable"
                );
            }
        }
    }

    /**
     * La descripción distingue los tres casos: cuenta exacta, prefijo con
     * cuentas hijas y prefijo inexistente.
     */
    public function testDescriptionBranches(): void
    {
        Modelo130Config::resetCache();

        // 60 existe como cuenta exacta en el plan español
        $account = new Cuenta();
        $this->assertTrue($account->loadWhere([Where::eq('codcuenta', '60')]));
        $this->assertSame(
            (string) $account->descripcion,
            Modelo130Config::description('60')
        );

        // ningún código empieza por 699999
        $this->assertSame(
            Tools::trans('model-130-prefix-not-found', ['%code%' => '699999']),
            Modelo130Config::description('699999')
        );

        /*
         * Prefijo sin cuenta exacta pero con cuentas hijas: el plan español
         * está completo en todos los niveles, así que se crea una cuenta más
         * profunda para poder comprobar esa rama.
         */
        $exercise = $this->exercise();

        $child = new Cuenta();
        $child->codejercicio = $exercise->codejercicio;
        $child->codcuenta = '620500';
        $child->descripcion = 'Cuenta de prueba Modelo 130';

        if (false === $child->save()) {
            $this->markTestSkipped('No se ha podido crear la cuenta de prueba.');
        }

        Modelo130Config::resetCache();

        $this->assertSame(
            Tools::trans('model-130-prefix-includes', ['%code%' => '62050']),
            Modelo130Config::description('62050')
        );

        $this->assertTrue($child->delete());
        Modelo130Config::resetCache();
    }

    /**
     * Ejercicio sobre el que se hacen las comprobaciones.
     */
    private function exercise(): Ejercicio
    {
        $list = (new Ejercicio())->all([], ['codejercicio' => 'DESC'], 0, 1);
        $this->assertNotEmpty($list, 'No hay ningún ejercicio en la base de datos');

        return $list[0];
    }

    /**
     * restoreDefaults() vacía la configuración y la reconstruye, y
     * ensureDefaults() no la toca cuando ya hay reglas.
     */
    public function testRestoreAndEnsureDefaults(): void
    {
        $originalRules = $this->snapshotRules();

        try {
            $this->assertTrue(Modelo130Config::restoreDefaults());
            Modelo130Accounts::resetCache();

            /*
             * Se comparan como conjuntos: buildDefaultRules() ordena con sort()
             * y prefixes() con el ORDER BY de la consulta, que no coinciden.
             */
            $defaults = Modelo130Config::buildDefaultRules();

            foreach ([Mod130Conf::TIPO_GASTO, Mod130Conf::TIPO_INGRESO] as $type) {
                $expected = $defaults[$type];
                $current = Modelo130Config::prefixes($type);

                sort($expected);
                sort($current);

                $this->assertSame($expected, $current);
            }

            // con una única regla, ensureDefaults() no debe repoblar
            $this->replaceRules([[Mod130Conf::TIPO_GASTO, '62']]);
            Modelo130Config::ensureDefaults();
            $this->assertSame(1, (new Mod130Conf())->count());
        } finally {
            $this->replaceRules($originalRules);
            Modelo130Accounts::resetCache();
        }
    }

    /**
     * prefixes() descarta las reglas cuyo código no corresponde con su tipo,
     * aunque estén guardadas en la tabla.
     */
    public function testPrefixesDiscardsInvalidRules(): void
    {
        $originalRules = $this->snapshotRules();

        try {
            $this->replaceRules([[Mod130Conf::TIPO_GASTO, '62']]);

            // se fuerza en base de datos una regla imposible de guardar por test()
            $db = new DataBase();
            $db->connect();
            $this->assertTrue($db->exec(
                'UPDATE ' . Mod130Conf::tableName()
                . ' SET tipo = ' . $db->var2str(Mod130Conf::TIPO_INGRESO)
                . ' WHERE codigo = ' . $db->var2str('62') . ';'
            ));

            $this->assertSame([], Modelo130Config::prefixes(Mod130Conf::TIPO_INGRESO));
            $this->assertSame([], Modelo130Config::prefixes(Mod130Conf::TIPO_GASTO));

            // un tipo desconocido no devuelve reglas
            $this->assertSame([], Modelo130Config::rules('otro'));
        } finally {
            $this->replaceRules($originalRules);
            Modelo130Accounts::resetCache();
        }
    }

    /**
     * El modelo rechaza códigos no numéricos, demasiado largos, con tipo
     * inválido o con un dígito inicial que no corresponde al tipo.
     */
    public function testRuleValidation(): void
    {
        $cases = [
            ['62A', Mod130Conf::TIPO_GASTO, 'código no numérico'],
            ['', Mod130Conf::TIPO_GASTO, 'código vacío'],
            [str_repeat('6', 16), Mod130Conf::TIPO_GASTO, 'código demasiado largo'],
            ['62', 'otro', 'tipo inválido'],
            ['72', Mod130Conf::TIPO_GASTO, 'gasto que no empieza por 6'],
            ['62', Mod130Conf::TIPO_INGRESO, 'ingreso que no empieza por 7'],
        ];

        foreach ($cases as [$codigo, $tipo, $motivo]) {
            $rule = new Mod130Conf();
            $rule->codigo = $codigo;
            $rule->tipo = $tipo;

            $this->assertFalse(
                $rule->save(),
                'No debería guardarse una regla con ' . $motivo
            );
        }
    }

    /**
     * No se puede repetir el mismo código en dos reglas.
     */
    public function testDuplicatedCodeIsRejected(): void
    {
        $originalRules = $this->snapshotRules();

        try {
            $this->replaceRules([[Mod130Conf::TIPO_GASTO, '655']]);

            $duplicated = new Mod130Conf();
            $duplicated->codigo = '655';
            $duplicated->tipo = Mod130Conf::TIPO_GASTO;

            $this->assertFalse(
                $duplicated->save(),
                'La restricción UNIQUE debe impedir códigos repetidos'
            );
        } finally {
            $this->replaceRules($originalRules);
            Modelo130Accounts::resetCache();
        }
    }

    /**
     * Las cuentas 61 y 71 se procesan como variación de existencias y no
     * como gastos o ingresos ordinarios. El signo del saldo decide la casilla.
     */
    public function testStockVariationClassification(): void
    {
        $this->assertTrue(Modelo130Accounts::isStockVariation('6100000000'));
        $this->assertTrue(Modelo130Accounts::isStockVariation('7199999999'));
        $this->assertFalse(Modelo130Accounts::isStockVariation('6000000000'));
        $this->assertFalse(Modelo130Accounts::isStockVariation('7000000000'));

        // Nunca deben pasar por la clasificación ordinaria: su signo depende
        // del saldo acumulado y se resuelve en Modelo130::loadAccountingData(),
        // que se prueba en Modelo130AccountingTest.
        $this->assertFalse(Modelo130Accounts::isExpense('6100000000'));
        $this->assertFalse(Modelo130Accounts::isIncome('6100000000'));
        $this->assertFalse(Modelo130Accounts::isExpense('7100000000'));
        $this->assertFalse(Modelo130Accounts::isIncome('7100000000'));
    }

    /**
     * La consulta contable debe incluir 61/71 únicamente cuando estén en la
     * configuración. La cuenta 473 permanece siempre incluida por código.
     */
    public function testQueryPrefixesRespectStockVariationConfiguration(): void
    {
        $originalRules = $this->snapshotRules();

        try {
            $this->replaceRules([
                [Mod130Conf::TIPO_GASTO, '60'],
                [Mod130Conf::TIPO_GASTO, '61'],
                [Mod130Conf::TIPO_INGRESO, '70'],
                [Mod130Conf::TIPO_INGRESO, '71'],
            ]);

            Modelo130Accounts::resetCache();
            $prefixes = Modelo130Accounts::queryPrefixes();
            $this->assertContains('61', $prefixes);
            $this->assertContains('71', $prefixes);
            $this->assertContains(Modelo130Accounts::WITHHOLDING_ACCOUNT, $prefixes);

            $this->replaceRules([
                [Mod130Conf::TIPO_GASTO, '60'],
                [Mod130Conf::TIPO_INGRESO, '70'],
            ]);

            Modelo130Accounts::resetCache();
            $prefixes = Modelo130Accounts::queryPrefixes();
            $this->assertNotContains('61', $prefixes);
            $this->assertNotContains('71', $prefixes);
            $this->assertContains(Modelo130Accounts::WITHHOLDING_ACCOUNT, $prefixes);
        } finally {
            $this->replaceRules($originalRules);
            Modelo130Accounts::resetCache();
        }
    }

    /**
     * Al crear una regla se guardan el usuario y la fecha de creación.
     */
    public function testRuleCreationIsLinkedToUser(): void
    {
        $originalRules = $this->snapshotRules();

        try {
            $this->replaceRules([
                [Mod130Conf::TIPO_GASTO, '699999999999999'],
            ]);

            $rule = new Mod130Conf();

            $this->assertTrue(
                $rule->loadWhere([
                    Where::eq('codigo', '699999999999999'),
                ]),
                'La regla de prueba debe existir'
            );

            $this->assertSame(
                Session::user()->nick,
                $rule->nick,
                'El nick de creación debe ser el usuario de la sesión'
            );

            $this->assertNotEmpty(
                $rule->creation_date,
                'La fecha de creación debe guardarse'
            );
        } finally {
            $this->replaceRules($originalRules);
        }
    }

    /**
     * Cuando las claves foráneas están habilitadas en FacturaScripts, la tabla
     * debe contener la relación física de nick con users.nick.
     */
    public function testUserForeignKeyExistsWhenEnabled(): void
    {
        if (!Tools::config('db_foreign_keys')) {
            $this->markTestSkipped('Las claves foráneas están desactivadas en la configuración.');
        }

        $db = new DataBase();
        $db->connect();
        $constraints = $db->getConstraints(Mod130Conf::tableName());
        $names = array_column($constraints, 'name');

        $this->assertContains('ca_mod130_conf_users_nick', $names);
        $this->assertNotContains('ca_mod130_conf_users_last_nick', $names);
        $this->assertContains('uniq_mod130_conf_codigo', $names);

        $columns = array_column(
            $db->getColumns(Mod130Conf::tableName()),
            null,
            'name'
        );
        $this->assertArrayNotHasKey('last_nick', $columns);
        $this->assertArrayNotHasKey('last_update', $columns);
    }

    /**
     * No se permiten reglas que engloben o queden englobadas por otra regla.
     */
    public function testOverlappingPrefixesAreDetected(): void
    {
        $originalRules = $this->snapshotRules();

        try {
            $this->replaceRules([
                [Mod130Conf::TIPO_GASTO, '64'],
                [Mod130Conf::TIPO_INGRESO, '70'],
            ]);

            $this->assertTrue(Modelo130Config::hasOverlap('642'));
            $this->assertTrue(Modelo130Config::hasOverlap('6'));
            $this->assertTrue(Modelo130Config::hasOverlap('700'));
            $this->assertFalse(Modelo130Config::hasOverlap('62'));
            $this->assertFalse(Modelo130Config::hasOverlap('71'));
        } finally {
            $this->replaceRules($originalRules);
        }
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function snapshotRules(): array
    {
        $result = [];

        foreach ((new Mod130Conf())->all([], ['codigo' => 'ASC'], 0, 0) as $rule) {
            $result[] = [(string) $rule->tipo, (string) $rule->codigo];
        }

        return $result;
    }

    /**
     * @param array<int, array{0: string, 1: string}> $rules
     */
    private function replaceRules(array $rules): void
    {
        foreach ((new Mod130Conf())->all([], [], 0, 0) as $rule) {
            $this->assertTrue($rule->delete(), 'No se pudo eliminar una regla de configuración de prueba');
        }

        foreach ($rules as [$type, $code]) {
            $rule = new Mod130Conf();
            $rule->tipo = $type;
            $rule->codigo = $code;

            $this->assertTrue(
                $rule->save(),
                "No se pudo guardar la regla de configuración {$code}"
            );
        }
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
