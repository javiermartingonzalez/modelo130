<?php

namespace FacturaScripts\Plugins\Modelo130\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Cuenta;
use FacturaScripts\Dinamic\Model\Mod130Conf;

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

    public static function ensureDefaults(): void
    {
        if ((new Mod130Conf())->count() === 0) {
            self::restoreDefaults();
        }
    }

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

    public static function description(string $code): string
    {
        $code = trim($code);

        $account = new Cuenta();

        if ($account->loadWhere([Where::eq('codcuenta', $code)])) {
            return (string) $account->descripcion;
        }

        /*
         * Las reglas pueden no corresponder con una cuenta exacta, pero sí
         * actuar como prefijo de cuentas hijas.
         */
        foreach ((new Cuenta())->all([], ['codcuenta' => 'ASC'], 0, 0) as $candidate) {
            if (str_starts_with((string) $candidate->codcuenta, $code)) {
                return Tools::lang()->trans(
                    'model-130-prefix-includes',
                    ['%code%' => $code]
                );
            }
        }

        return Tools::lang()->trans(
            'model-130-prefix-not-found',
            ['%code%' => $code]
        );
    }

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
        $result = [];

        foreach ((new Cuenta())->all([], ['codcuenta' => 'ASC'], 0, 0) as $account) {
            $code = trim((string) $account->codcuenta);

            if ($code === '' || !ctype_digit($code)) {
                continue;
            }

            $result[$code] = (string) $account->descripcion;
        }

        return $result;
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