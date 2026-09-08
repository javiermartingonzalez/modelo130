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

use FacturaScripts\Core\DataSrc\Ejercicios;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Cuenta;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Plugins\Modelo130\Lib\Modelo130Accounts;
use FacturaScripts\Plugins\Modelo130\Lib\Modelo130Config;

/**
 * Preparación común de las pruebas del plugin.
 *
 * Todas las pruebas necesitan un ejercicio con plan contable importado. En una
 * base de datos recién creada (integración continua) no existe ningún
 * ejercicio, y installAccountingPlan() del core solo importa el plan en los
 * ejercicios que ya existen, así que sin este paso previo las pruebas se
 * ejecutarían sin ninguna cuenta contable.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
trait Modelo130Fixtures
{
    /**
     * Crea el ejercicio del año en curso si no hay ninguno e importa en él el
     * plan contable predeterminado.
     */
    protected static function ensureExerciseWithAccountingPlan(): void
    {
        Ejercicios::clear();

        if (empty(Ejercicios::all())) {
            $exercise = new Ejercicio();
            $exercise->idempresa = (int) Tools::settings('default', 'idempresa', 1);
            $exercise->loadFromDate(Tools::date());

            Ejercicios::clear();
        }

        self::installAccountingPlan();

        /*
         * El listado de cuentas se memoriza en cachés estáticas que sobreviven
         * entre clases de prueba, así que hay que olvidarlas después de
         * importar el plan contable.
         */
        Modelo130Config::resetCache();
        Modelo130Accounts::resetCache();
    }

    /**
     * Indica si el plan contable está disponible, para poder omitir las
     * pruebas que no tienen sentido sin cuentas contables.
     */
    protected static function hasAccountingPlan(): bool
    {
        return Cuenta::count() > 0;
    }
}
