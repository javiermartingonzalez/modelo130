<?php
/**
 * This file is part of Modelo130 plugin for FacturaScripts
 * Copyright (C) 2024-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Subcuenta;
use FacturaScripts\Dinamic\Model\User;

/**
 * Relación entre una subcuenta contable y su tipo para el modelo 130
 */
class Subcuenta130 extends ModelClass
{
    use ModelTrait;

    const TIPO_DEDUCIBLE = 'deducible';
    const TIPO_INGRESO = 'ingreso';

    /** @var string código de la subcuenta contable asociada */
    public $codsubcuenta;

    /** @var string fecha de creación del registro */
    public $creation_date;

    /** @var int clave primaria */
    public $id;

    /** @var string nick del usuario que realizó la última modificación */
    public $last_nick;

    /** @var string fecha de la última modificación del registro */
    public $last_update;

    /** @var string nombre descriptivo de la subcuenta */
    public $name;

    /** @var string nick del usuario que creó el registro */
    public $nick;

    /** @var string tipo de subcuenta: deducible o ingreso */
    public $tipo = self::TIPO_DEDUCIBLE;

    public function clear(): void
    {
        parent::clear();
        $this->tipo = self::TIPO_DEDUCIBLE;
    }

    public function getSubcuenta(): Subcuenta
    {
        $subcuenta = new Subcuenta();
        $where = [Where::eq('codsubcuenta', $this->codsubcuenta)];
        $subcuenta->loadWhere($where);
        return $subcuenta;
    }

    public function install(): string
    {
        new User();
        new Subcuenta();

        return parent::install();
    }

    public static function tableName(): string
    {
        return "subcuentas_130";
    }

    public function test(): bool
    {
        if (empty($this->id())) {
            $this->creation_date = Tools::dateTime();
            $this->last_nick = null;
            $this->last_update = null;
            $this->nick = Session::user()->nick;
        } else {
            $this->creation_date = $this->creationdate ?? Tools::dateTime();
            $this->last_nick = Session::user()->nick;
            $this->last_update = Tools::dateTime();
            $this->nick = $this->nick ?? Session::user()->nick;
        }

        $this->codsubcuenta = trim(Tools::noHtml((string)$this->codsubcuenta));
        $this->name = Tools::noHtml($this->name);
        $this->tipo = in_array($this->tipo, [self::TIPO_DEDUCIBLE, self::TIPO_INGRESO], true)
            ? $this->tipo
            : self::TIPO_DEDUCIBLE;

        if (strlen($this->codsubcuenta) < 1 || strlen($this->codsubcuenta) > 15) {
            Tools::log()->warning('invalid-column-lenght', [
                '%column%' => 'codsubcuenta',
                '%min%' => '1',
                '%max%' => '15'
            ]);
            return false;
        }

        return parent::test();
    }
}
