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

namespace FacturaScripts\Plugins\Modelo130\Model;

use FacturaScripts\Core\Session;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;

/**
 * Regla de configuración del Modelo 130: un prefijo de cuenta contable y el
 * tipo con el que debe computarse, gasto deducible o ingreso computable.
 *
 * Los códigos se interpretan como prefijos, de forma que la regla 62 incluye
 * cualquier subcuenta cuyo código comience por 62.
 *
 * @author Javier Martín González     <javier@javiermarting.es>
 * @author Daniel Fernández Giménez   <contacto@danielfg.es>
 */
class Mod130Conf extends ModelClass
{
    use ModelTrait;

    /** Reglas que computan como gasto deducible. Códigos del grupo 6. */
    public const TIPO_GASTO = 'gasto';

    /** Reglas que computan como ingreso computable. Códigos del grupo 7. */
    public const TIPO_INGRESO = 'ingreso';

    /** @var string Prefijo de cuenta contable. */
    public $codigo;

    /** @var string Fecha y hora de creación de la regla. */
    public $creation_date;

    /** @var int Clave primaria. */
    public $id;

    /** @var string Usuario que creó la regla. */
    public $nick;

    /** @var string Tipo de regla: gasto o ingreso. */
    public $tipo;

    public function clear(): void
    {
        parent::clear();
        $this->tipo = self::TIPO_GASTO;
    }

    /**
     * Crea la tabla comprobando antes la de usuarios, por la clave foránea de
     * la columna nick.
     */
    public function install(): string
    {
        new User();
        return parent::install();
    }

    public static function tableName(): string
    {
        return 'mod130_conf';
    }

    /** Usuario que creó la regla. */
    public function user(): ?User
    {
        return $this->belongsTo(User::class, 'nick');
    }

    /**
     * Valida la regla antes de guardarla: el código debe ser numérico, de como
     * máximo 15 caracteres, y empezar por 6 si es un gasto o por 7 si es un
     * ingreso.
     */
    public function test(): bool
    {
        $this->codigo = trim(Tools::noHtml((string) $this->codigo));
        $this->tipo = trim(Tools::noHtml((string) $this->tipo));

        if ($this->codigo === '' || !ctype_digit($this->codigo) || strlen($this->codigo) > 15) {
            Tools::log()->warning('model-130-invalid-account-prefix');
            return false;
        }

        if (!in_array($this->tipo, [self::TIPO_GASTO, self::TIPO_INGRESO], true)) {
            Tools::log()->warning('model-130-invalid-account-type');
            return false;
        }

        if ($this->tipo === self::TIPO_GASTO && !str_starts_with($this->codigo, '6')) {
            Tools::log()->warning('model-130-expense-must-start-with-6');
            return false;
        }

        if ($this->tipo === self::TIPO_INGRESO && !str_starts_with($this->codigo, '7')) {
            Tools::log()->warning('model-130-income-must-start-with-7');
            return false;
        }

        if (empty($this->id())) {
            $this->creation_date = Tools::dateTime();
            $this->nick = Session::user()->nick;
        } else {
            $this->creation_date = $this->creation_date ?? Tools::dateTime();
            $this->nick = $this->nick ?? Session::user()->nick;
        }

        return parent::test();
    }
}
