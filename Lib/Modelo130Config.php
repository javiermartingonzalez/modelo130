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

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Cuenta;
use FacturaScripts\Dinamic\Model\Mod130Conf;

/**
 * Gestiona la configuración de prefijos contables del Modelo 130: reglas
 * guardadas en Mod130Conf, valores predeterminados según el plan contable
 * instalado y validaciones de solapamiento entre reglas.
 *
 * @author Javier Martín González       <javier@javiermarting.es>
 * @author Daniel Fernández Giménez     <contacto@danielfg.es>
 */
class Modelo130Config
{
    /**
     * Prefijos incluidos por defecto en el Modelo 130.
     *
     * Al restaurar la configuración, cada prefijo solo se guarda cuando
     * existe la cuenta exacta o alguna cuenta hija en el plan
     * contable instalado.
     *
     * Por ejemplo, el prefijo 697 se incluye cuando existe 697, o bien
     *  6970, 6971 o cualquier otra cuenta cuyo código comience por 697.
     */
    private const DEFAULTS = [
        Mod130Conf::TIPO_GASTO => [
            '60',  // Compras.
            '61',  // Variación de existencias.
            '62',  // Servicios exteriores.
            '631', // Otros tributos.
            '634', // Ajustes negativos en la imposición indirecta.
            '636', // Devolución de impuestos.
            '639', // Ajustes positivos en la imposición indirecta.
            '64',  // Gastos de personal.
            '65',  // Otros gastos de gestión.
            '66',  // Gastos financieros.
            '675', // Pérdidas por operaciones con obligaciones propias.
            '678', // Gastos excepcionales.
            '68',  // Dotaciones para amortizaciones.
            '693', // Pérdidas por deterioro de existencias.
            '694', // Pérdidas por deterioro de créditos por operaciones comerciales.
            '695', // Dotación a la provisión por operaciones comerciales.
            '697', // Pérdidas por deterioro de créditos a largo plazo.
            '699', // Pérdidas por deterioro de créditos a corto plazo.
        ],

        Mod130Conf::TIPO_INGRESO => [
            '70',  // Ventas de mercaderías, de producción propia, de servicios, etc.
            '71',  // Variación de existencias.
            '73',  // Trabajos realizados para la empresa.
            '74',  // Subvenciones, donaciones y legados.
            '75',  // Otros ingresos de gestión.
            '76',  // Ingresos financieros.
            '778', // Ingresos excepcionales.
            '793', // Reversión del deterioro de existencias.
            '794', // Reversión del deterioro de créditos por operaciones comerciales.
        ],
    ];

    /**
     * Listado de cuentas del plan contable memorizado, indexado por código.
     *
     * description() se llama una vez por regla al pintar la configuración, así
     * que sin memorizar recorrería la tabla de cuentas decenas de veces.
     *
     * @var array<string, string>|null
     */
    private static $accountsCache = null;

    /**
     * Devuelve las reglas guardadas del tipo indicado, ordenadas por código.
     *
     * @return Mod130Conf[]
     */
    public static function rules(string $type): array
    {
        if (!in_array(
            $type,
            [Mod130Conf::TIPO_GASTO, Mod130Conf::TIPO_INGRESO],
            true
        )) {
            return [];
        }

        return (new Mod130Conf())->all(
            [Where::eq('tipo', $type)],
            ['codigo' => 'ASC'],
            0,
            0
        );
    }

    /**
     * Devuelve los prefijos contables válidos del tipo indicado.
     *
     * Descarta las reglas cuyo código no corresponde con su tipo (gastos que no
     * empiezan por 6 o ingresos que no empiezan por 7).
     *
     * @return string[]
     */
    public static function prefixes(string $type): array
    {
        $result = [];

        foreach (self::rules($type) as $rule) {
            if (self::isValidRule($rule)) {
                $result[] = $rule->codigo;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Carga la configuración predeterminada si todavía no hay ninguna regla.
     */
    public static function ensureDefaults(): void
    {
        if ((new Mod130Conf())->count() === 0) {
            self::restoreDefaults();
        }
    }

    /**
     * Borra la configuración actual y la reconstruye desde el plan contable
     * instalado.
     */
    public static function restoreDefaults(): bool
    {
        foreach ((new Mod130Conf())->all([], [], 0, 0) as $rule) {
            if (!$rule->delete()) {
                return false;
            }
        }

        foreach (self::buildDefaultRules() as $type => $codes) {
            foreach ($codes as $code) {
                $rule = new Mod130Conf();
                $rule->codigo = $code;
                $rule->tipo = $type;

                if (!$rule->save()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Construye las reglas predeterminadas que tienen sentido en el plan
     * contable instalado.
     *
     * @return array<string, string[]>
     */
    public static function buildDefaultRules(): array
    {
        $available = self::availableAccounts();

        $result = [
            Mod130Conf::TIPO_GASTO => [],
            Mod130Conf::TIPO_INGRESO => [],
        ];

        foreach (self::DEFAULTS as $type => $codes) {
            foreach ($codes as $code) {
                /*
                 * DEFAULTS contiene prefijos.
                 *
                 * Se añade el código cuando existe la cuenta exacta o alguna
                 * cuenta hija en el plan contable instalado.
                 *
                 * Ejemplo: 697 se añade cuando existe 6970, aunque no exista
                 * una cuenta cuyo código sea exactamente 697.
                 */
                if (self::prefixExists($code, $available)) {
                    $result[$type][] = $code;
                }
            }

            $result[$type] = array_values(array_unique($result[$type]));
            sort($result[$type]);
        }

        return $result;
    }

    /**
     * Devuelve el texto descriptivo de un prefijo para mostrarlo en la
     * configuración: la descripción de la cuenta cuando existe exactamente, un
     * aviso de que actúa como prefijo cuando solo hay cuentas hijas, o un aviso
     * de que no existe en el plan contable actual.
     */
    public static function description(string $code): string
    {
        $code = trim($code);
        $available = self::availableAccounts();

        if (isset($available[$code])) {
            return $available[$code];
        }

        /*
         * Las reglas pueden no corresponder con una cuenta exacta, pero sí
         * actuar como prefijo de cuentas hijas.
         */
        if (self::prefixExists($code, $available)) {
            return Tools::trans(
                'model-130-prefix-includes',
                ['%code%' => $code]
            );
        }

        return Tools::trans(
            'model-130-prefix-not-found',
            ['%code%' => $code]
        );
    }

    /**
     * Comprueba si un código engloba a una regla existente o queda englobado
     * por ella, para no contabilizar dos veces la misma cuenta.
     *
     * @param int|null $excludeId regla que no debe considerarse (al editar)
     */
    public static function hasOverlap(string $code, ?int $excludeId = null): bool
    {
        $code = trim($code);

        foreach ((new Mod130Conf())->all([], [], 0, 0) as $rule) {
            /*
             * Al editar una regla no debe detectarse a sí misma como
             * solapamiento.
             */
            if ($excludeId !== null && (int) $rule->id === $excludeId) {
                continue;
            }

            if (
                str_starts_with($code, (string) $rule->codigo)
                || str_starts_with((string) $rule->codigo, $code)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Devuelve exclusivamente las cuentas que existen realmente en Cuenta.
     *
     * La comprobación de prefijos se realiza posteriormente mediante prefixExists().
     */
    private static function availableAccounts(): array
    {
        if (null !== self::$accountsCache) {
            return self::$accountsCache;
        }

        $result = [];

        foreach ((new Cuenta())->all([], ['codcuenta' => 'ASC'], 0, 0) as $account) {
            $code = trim((string) $account->codcuenta);

            if ($code === '' || !ctype_digit($code)) {
                continue;
            }

            $result[$code] = (string) $account->descripcion;
        }

        self::$accountsCache = $result;

        return $result;
    }

    /**
     * Olvida el listado de cuentas memorizado.
     */
    public static function resetCache(): void
    {
        self::$accountsCache = null;
    }

    /**
     * Comprueba si existe la cuenta exacta o alguna cuenta hija.
     */
    private static function prefixExists(string $prefix, array $available): bool
    {
        foreach (array_keys($available) as $code) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function isValidRule(Mod130Conf $rule): bool
    {
        return (
            $rule->tipo === Mod130Conf::TIPO_GASTO
            && str_starts_with((string) $rule->codigo, '6')
        ) || (
            $rule->tipo === Mod130Conf::TIPO_INGRESO
            && str_starts_with((string) $rule->codigo, '7')
        );
    }
}