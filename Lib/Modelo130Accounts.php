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

namespace FacturaScripts\Plugins\Modelo130\Lib;

/**
 * Centraliza las cuentas contables utilizadas para calcular el Modelo 130.
 *
 * Los códigos indicados son prefijos. Por ejemplo, el código 64 incluye
 * cualquier subcuenta que comience por 64.
 *
 * Esta clase aplica una clasificación automática y conservadora basada
 * exclusivamente en el código contable:
 *
 * - incluye las cuentas normalmente computables en estimación directa;
 * - excluye las partidas que, con carácter general, no forman parte del
 *   rendimiento de la actividad o se calculan fuera de esta clase;
 * - trata las variaciones de existencias según su saldo acumulado.
 */
final class Modelo130Accounts
{
    /**
     * Hacienda pública, retenciones y pagos a cuenta.
     *
     * La cuenta 473 puede contener:
     * - retenciones practicadas en facturas;
     * - pagos fraccionados de trimestres anteriores.
     *
     * La diferencia se determina comprobando si el asiento tiene una factura
     * asociada.
     */
    public const WITHHOLDING_ACCOUNT = '473';

    /**
     * Cuentas de gastos computables.
     *
     * Las variaciones de existencias 61 y 71 se tratan por separado porque
     * su clasificación depende del saldo deudor o acreedor acumulado.
     *
     * @return array<string, string>
     */
    public static function expenses(): array
    {
        return [
            '60' => 'Compras',
            '62' => 'Servicios exteriores',
            '63' => 'Tributos',
            '64' => 'Gastos de personal',
            '65' => 'Otros gastos de gestión',
            '66' => 'Gastos financieros',
            '67' => 'Pérdidas procedentes de activos no corrientes y gastos excepcionales',
            '68' => 'Dotaciones para amortizaciones',
            '69' => 'Pérdidas por deterioro y otras dotaciones',
        ];
    }

    /**
     * Cuentas excluidas aunque pertenezcan a un subgrupo de gastos.
     *
     * Se excluyen:
     *
     * - el impuesto sobre beneficios y sus ajustes;
     * - resultados de instrumentos financieros y créditos no comerciales;
     * - pérdidas derivadas de activos no corrientes;
     * - gastos excepcionales, por ser una cuenta mixta utilizada habitualmente
     *   para sanciones, recargos y otros conceptos no deducibles;
     * - deterioros fiscalmente no deducibles;
     * - provisiones calculadas posteriormente por el módulo fiscal.
     *
     * @return array<string, string>
     */
    public static function excludedExpenses(): array
    {
        return [
            '630' => 'Impuesto sobre beneficios',
            '633' => 'Ajustes negativos en la imposición sobre beneficios',
            '638' => 'Ajustes positivos en la imposición sobre beneficios',

            '660' => 'Actualización financiera de provisiones calculadas fuera de esta clase',
            '663' => 'Pérdidas por valoración de instrumentos financieros',
            '664' => 'Dividendos de instrumentos considerados pasivos financieros',
            '666' => 'Pérdidas en participaciones y valores representativos de deuda',
            '667' => 'Pérdidas de créditos no comerciales',

            '670' => 'Pérdidas procedentes del inmovilizado intangible',
            '671' => 'Pérdidas procedentes del inmovilizado material',
            '672' => 'Pérdidas procedentes de inversiones inmobiliarias',
            '673' => 'Pérdidas procedentes de participaciones a largo plazo',
            '675' => 'Pérdidas por operaciones con obligaciones propias',
            '678' => 'Gastos excepcionales excluidos por defecto',

            '690' => 'Deterioro del inmovilizado intangible',
            '691' => 'Deterioro del inmovilizado material',
            '692' => 'Deterioro de inversiones inmobiliarias',
            '695' => 'Provisiones calculadas posteriormente por el módulo fiscal',
            '696' => 'Deterioro de participaciones y valores a largo plazo',
            '697' => 'Deterioro de créditos no comerciales a largo plazo',
            '698' => 'Deterioro de participaciones y valores a corto plazo',
            '699' => 'Deterioro de créditos no comerciales a corto plazo',
        ];
    }

    /**
     * Cuentas de ingresos computables.
     *
     * El subgrupo 76 no se incluye porque el código contable no permite
     * distinguir los intereses comerciales computables de los rendimientos
     * del capital mobiliario ajenos al Modelo 130.
     *
     * Las variaciones de existencias 61 y 71 se tratan por separado.
     *
     * @return array<string, string>
     */
    public static function incomes(): array
    {
        return [
            '70' => 'Ventas de mercaderías, de producción propia, de servicios, etc.',
            '73' => 'Trabajos realizados para la empresa',
            '74' => 'Subvenciones, donaciones y legados',
            '75' => 'Otros ingresos de gestión',
            '77' => 'Beneficios procedentes de activos no corrientes e ingresos excepcionales',
            '79' => 'Excesos y aplicaciones de provisiones y de pérdidas por deterioro',
        ];
    }

    /**
     * Cuentas excluidas aunque pertenezcan a un subgrupo de ingresos.
     *
     * Se mantienen como ingresos computables del grupo 77 únicamente los
     * ingresos excepcionales de la cuenta 778, y del grupo 79 las reversiones
     * de existencias y créditos comerciales de las cuentas 793 y 794.
     *
     * @return array<string, string>
     */
    public static function excludedIncomes(): array
    {
        return [
            '770' => 'Beneficios procedentes del inmovilizado intangible',
            '771' => 'Beneficios procedentes del inmovilizado material',
            '772' => 'Beneficios procedentes de inversiones inmobiliarias',
            '773' => 'Beneficios procedentes de participaciones a largo plazo',
            '774' => 'Diferencia negativa en combinaciones de negocios',
            '775' => 'Beneficios por operaciones con obligaciones propias',

            '790' => 'Reversión del deterioro del inmovilizado intangible',
            '791' => 'Reversión del deterioro del inmovilizado material',
            '792' => 'Reversión del deterioro de inversiones inmobiliarias',
            '795' => 'Excesos de provisiones calculadas fuera de esta clase',
            '796' => 'Reversión de deterioros financieros a largo plazo',
            '797' => 'Reversión de deterioros de créditos no comerciales a largo plazo',
            '798' => 'Reversión de deterioros financieros a corto plazo',
            '799' => 'Reversión de deterioros de créditos no comerciales a corto plazo',
        ];
    }

    /**
     * Cuentas de variación de existencias.
     *
     * Un saldo deudor se lleva a gastos y un saldo acreedor a ingresos.
     *
     * @return array<string, string>
     */
    public static function stockVariations(): array
    {
        return [
            '61' => 'Variación de existencias de compras',
            '71' => 'Variación de existencias de producción',
        ];
    }

    /**
     * Comprueba si una subcuenta debe considerarse gasto.
     *
     * No incluye las cuentas 61 y 71, que deben procesarse con los métodos
     * stockVariationExpense() y stockVariationIncome().
     */
    public static function isExpense(string $code): bool
    {
        $code = trim($code);

        if ($code === '') {
            return false;
        }

        if (static::matches($code, array_keys(static::excludedExpenses()))) {
            return false;
        }

        return static::matches(
            $code,
            array_keys(static::expenses())
        );
    }

    /**
     * Comprueba si una subcuenta debe considerarse ingreso.
     *
     * No incluye las cuentas 61 y 71, que deben procesarse con los métodos
     * stockVariationExpense() y stockVariationIncome().
     */
    public static function isIncome(string $code): bool
    {
        $code = trim($code);

        if ($code === '') {
            return false;
        }

        if (static::matches($code, array_keys(static::excludedIncomes()))) {
            return false;
        }

        return static::matches(
            $code,
            array_keys(static::incomes())
        );
    }

    /**
     * Comprueba si una subcuenta corresponde a una variación de existencias.
     */
    public static function isStockVariation(string $code): bool
    {
        return static::matches(
            trim($code),
            array_keys(static::stockVariations())
        );
    }

    /**
     * Devuelve la parte de una variación de existencias que debe computarse
     * como gasto.
     *
     * Los importes deben ser los acumulados desde el 1 de enero.
     */
    public static function stockVariationExpense(
        float $debit,
        float $credit
    ): float {
        return max($debit - $credit, 0.0);
    }

    /**
     * Devuelve la parte de una variación de existencias que debe computarse
     * como ingreso.
     *
     * Los importes deben ser los acumulados desde el 1 de enero.
     */
    public static function stockVariationIncome(
        float $debit,
        float $credit
    ): float {
        return max($credit - $debit, 0.0);
    }

    /**
     * Comprueba si una subcuenta pertenece a la cuenta 473.
     */
    public static function isWithholding(string $code): bool
    {
        return str_starts_with(
            trim($code),
            static::WITHHOLDING_ACCOUNT
        );
    }

    /**
     * Devuelve todos los prefijos que deben buscarse en contabilidad.
     *
     * Se usa para construir la consulta SQL inicial y evitar cargar partidas
     * que nunca van a intervenir en el Modelo 130.
     *
     * @return string[]
     */
    public static function queryPrefixes(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(static::expenses()),
            array_keys(static::incomes()),
            array_keys(static::stockVariations()),
            [static::WITHHOLDING_ACCOUNT]
        )));
    }

    /**
     * Comprueba si un código comienza por alguno de los prefijos indicados.
     *
     * @param string[] $prefixes
     */
    private static function matches(string $code, array $prefixes): bool
    {
        if ($code === '') {
            return false;
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }
}