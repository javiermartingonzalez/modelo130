<?php
namespace FacturaScripts\Plugins\Modelo130\Model;

use FacturaScripts\Core\Session;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;

class Mod130Conf extends ModelClass
{
    use ModelTrait;

    public const TIPO_GASTO = 'gasto';
    public const TIPO_INGRESO = 'ingreso';

    public $codigo;
    public $creation_date;
    public $id;
    public $nick;
    public $tipo;

    public function clear(): void
    {
        parent::clear();
        $this->tipo = self::TIPO_GASTO;
    }

    public function install(): string
    {
        new User();
        return parent::install();
    }

    public static function tableName(): string
    {
        return 'mod130_conf';
    }

    public function user(): ?User
    {
        return $this->belongsTo(User::class, 'nick');
    }

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
