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

    /** @return string[] */
    public static function expenses(): array
    {
        Modelo130Config::ensureDefaults();
        return Modelo130Config::prefixes(\FacturaScripts\Dinamic\Model\Mod130Conf::TIPO_GASTO);
    }

    /** @return string[] */
    public static function incomes(): array
    {
        Modelo130Config::ensureDefaults();
        return Modelo130Config::prefixes(\FacturaScripts\Dinamic\Model\Mod130Conf::TIPO_INGRESO);
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

        if ($code === '' || static::isStockVariation($code)) {
            return false;
        }

        return static::matches($code, static::expenses());
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

        if ($code === '' || static::isStockVariation($code)) {
            return false;
        }

        return static::matches($code, static::incomes());
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
            static::expenses(),
            static::incomes(),
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