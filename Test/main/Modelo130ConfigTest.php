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
use FacturaScripts\Dinamic\Model\Mod130Conf;
use FacturaScripts\Dinamic\Model\User;
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
     * Los valores predeterminados deben corresponder con cuentas que existan
     * exactamente en el plan contable y deben mostrar su descripción real.
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
                $account = new Cuenta();
                $exists = $account->loadWhere([Where::eq('codcuenta', $code)]);

                $this->assertTrue(
                    $exists,
                    "El prefijo predeterminado {$code} debe existir exactamente en Cuenta"
                );
                $this->assertStringStartsWith($firstDigit, $code);
                $this->assertSame(
                    (string) $account->descripcion,
                    Modelo130Config::description($code),
                    "La descripción de {$code} debe proceder del plan contable"
                );
            }
        }
    }

    /**
     * Cada inclusión declarada en DEFAULTS debe añadirse cuando la cuenta
     * exacta está disponible en el PGC instalado.
     */
    public function testAvailableDeclaredDefaultsAreIncluded(): void
    {
        $defaults = Modelo130Config::buildDefaultRules();
        $declared = [
            Mod130Conf::TIPO_GASTO => [
                '60', '61', '62', '631', '634', '636', '639',
                '64', '65', '661', '662', '665', '668', '669',
                '68', '693', '694',
            ],
            Mod130Conf::TIPO_INGRESO => [
                '70', '71', '73', '74', '75', '778', '793', '794',
            ],
        ];

        foreach ($declared as $type => $codes) {
            foreach ($codes as $code) {
                $account = new Cuenta();

                if (!$account->loadWhere([Where::eq('codcuenta', $code)])) {
                    continue;
                }

                $this->assertContains(
                    $code,
                    $defaults[$type],
                    "La cuenta existente {$code} debe incluirse en los valores predeterminados"
                );
            }
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

        // Nunca deben pasar por la clasificación ordinaria.
        $this->assertFalse(Modelo130Accounts::isExpense('6100000000'));
        $this->assertFalse(Modelo130Accounts::isIncome('6100000000'));
        $this->assertFalse(Modelo130Accounts::isExpense('7100000000'));
        $this->assertFalse(Modelo130Accounts::isIncome('7100000000'));

        // Saldo deudor: gasto.
        $this->assertSame(750.0, Modelo130Accounts::stockVariationExpense(1000.0, 250.0));
        $this->assertSame(0.0, Modelo130Accounts::stockVariationIncome(1000.0, 250.0));

        // Saldo acreedor: ingreso.
        $this->assertSame(0.0, Modelo130Accounts::stockVariationExpense(250.0, 1000.0));
        $this->assertSame(750.0, Modelo130Accounts::stockVariationIncome(250.0, 1000.0));

        // Saldo compensado: no computa en ninguna de las dos casillas.
        $this->assertSame(0.0, Modelo130Accounts::stockVariationExpense(500.0, 500.0));
        $this->assertSame(0.0, Modelo130Accounts::stockVariationIncome(500.0, 500.0));
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

            $prefixes = Modelo130Accounts::queryPrefixes();
            $this->assertContains('61', $prefixes);
            $this->assertContains('71', $prefixes);
            $this->assertContains(Modelo130Accounts::WITHHOLDING_ACCOUNT, $prefixes);

            $this->replaceRules([
                [Mod130Conf::TIPO_GASTO, '60'],
                [Mod130Conf::TIPO_INGRESO, '70'],
            ]);

            $prefixes = Modelo130Accounts::queryPrefixes();
            $this->assertNotContains('61', $prefixes);
            $this->assertNotContains('71', $prefixes);
            $this->assertContains(Modelo130Accounts::WITHHOLDING_ACCOUNT, $prefixes);
        } finally {
            $this->replaceRules($originalRules);
        }
    }

    /**
     * Al crear una regla se guardan el usuario y la fecha de creación, y el
     * nick corresponde con un usuario existente.
     */
    public function testRuleCreationIsLinkedToUser(): void
    {
        $originalRules = $this->snapshotRules();

        try {
            $this->replaceRules([
                [Mod130Conf::TIPO_GASTO, '699999999999999'],
            ]);

            $rule = new Mod130Conf();
            $this->assertTrue($rule->loadWhere([Where::eq('codigo', '699999999999999')]));

            $currentNick = Session::user()->nick;
            $this->assertSame($currentNick, $rule->nick);
            $this->assertNotEmpty($rule->creation_date);

            $user = new User();
            $this->assertTrue(
                $user->loadWhere([Where::eq('nick', $rule->nick)]),
                'El nick de creación debe corresponder con un usuario existente'
            );
            $this->assertSame($currentNick, $rule->user()?->nick);
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
