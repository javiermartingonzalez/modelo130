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

use FacturaScripts\Dinamic\Model\Mod130Conf;

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
 *
 * Los prefijos de gasto e ingreso se leen de la configuración una única vez
 * por petición y quedan memorizados, ya que isExpense() e isIncome() se
 * invocan una vez por cada partida contable del período.
 *
 * @author Carlos Garcia Gomez        <carlos@facturascripts.com>
 * @author Javier Martín González     <javier@javiermarting.es>
 * @author Daniel Fernández Giménez   <contacto@danielfg.es>
 */
class Modelo130Accounts
{
    /**
     * Prefijo por defecto de Hacienda pública, retenciones y pagos a cuenta.
     *
     * La cuenta 473 puede contener:
     * - retenciones practicadas en facturas;
     * - pagos fraccionados de trimestres anteriores.
     *
     * La diferencia se determina comprobando si el asiento tiene una factura
     * asociada.
     *
     * Se utiliza como respaldo cuando el plan contable no tiene ninguna cuenta
     * marcada como especial IRPF.
     */
    public const WITHHOLDING_ACCOUNT = '473';

    /**
     * Prefijos de gasto memorizados. null mientras no se han cargado.
     *
     * @var string[]|null
     */
    private static $expenses = null;

    /**
     * Prefijos de ingreso memorizados. null mientras no se han cargado.
     *
     * @var string[]|null
     */
    private static $incomes = null;

    /**
     * Prefijo de la cuenta de retenciones en uso.
     *
     * @var string
     */
    private static $withholdingPrefix = self::WITHHOLDING_ACCOUNT;

    /**
     * Devuelve los prefijos de las cuentas de gasto configuradas.
     *
     * @return string[]
     */
    public static function expenses(): array
    {
        if (null === self::$expenses) {
            Modelo130Config::ensureDefaults();
            self::$expenses = Modelo130Config::prefixes(Mod130Conf::TIPO_GASTO);
        }

        return self::$expenses;
    }

    /**
     * Devuelve los prefijos de las cuentas de ingreso configuradas.
     *
     * @return string[]
     */
    public static function incomes(): array
    {
        if (null === self::$incomes) {
            Modelo130Config::ensureDefaults();
            self::$incomes = Modelo130Config::prefixes(Mod130Conf::TIPO_INGRESO);
        }

        return self::$incomes;
    }

    /**
     * Olvida los prefijos memorizados.
     *
     * Debe llamarse al iniciar un cálculo y después de cualquier alta, baja o
     * restauración de la configuración, para que el cambio se refleje en la
     * misma petición.
     */
    public static function resetCache(): void
    {
        self::$expenses = null;
        self::$incomes = null;
        self::$withholdingPrefix = self::WITHHOLDING_ACCOUNT;
    }

    /**
     * Devuelve el prefijo de la cuenta de retenciones y pagos a cuenta en uso.
     */
    public static function withholdingPrefix(): string
    {
        return self::$withholdingPrefix;
    }

    /**
     * Fija el prefijo de la cuenta de retenciones y pagos a cuenta.
     *
     * Permite trabajar con planes contables en los que la cuenta especial IRPF
     * no es la 473. Un valor vacío restaura el prefijo por defecto.
     */
    public static function setWithholdingPrefix(?string $prefix): void
    {
        $prefix = trim((string) $prefix);

        self::$withholdingPrefix = $prefix === ''
            ? self::WITHHOLDING_ACCOUNT
            : $prefix;
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
     * No incluye las cuentas 61 y 71, cuya clasificación depende del saldo
     * acumulado del período y se resuelve en Modelo130::loadAccountingData().
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
     * No incluye las cuentas 61 y 71, cuya clasificación depende del saldo
     * acumulado del período y se resuelve en Modelo130::loadAccountingData().
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
     * Comprueba si una subcuenta pertenece a la cuenta de retenciones y pagos
     * a cuenta (473 salvo que el plan contable indique otra).
     */
    public static function isWithholding(string $code): bool
    {
        return str_starts_with(
            trim($code),
            static::withholdingPrefix()
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
            [static::withholdingPrefix()]
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
