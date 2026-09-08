<?php
/**
 * This file is part of Modelo130 plugin for FacturaScripts
 * Copyright (C) 2021-2026 Carlos Garcia Gomez            <carlos@facturascripts.com>
 *                         Jeronimo Pedro Sánchez Manzano <socger@gmail.com>
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

namespace FacturaScripts\Plugins\Modelo130\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\DataSrc\Ejercicios;
use FacturaScripts\Core\Response;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\Mod130Conf;
use FacturaScripts\Dinamic\Lib\Modelo130 as DinModelo130;
use FacturaScripts\Dinamic\Lib\Modelo130Export as DinModelo130Export;
use FacturaScripts\Dinamic\Lib\Modelo130Config as DinModelo130Config;

/**
 * Description of Modelo130
 *
 * @author Carlos Garcia Gomez            <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez       <contacto@danielfg.es>
 * @author Jerónimo Pedro Sánchez Manzano <socger@gmail.com>
 * @author Javier Martín González         <javier@javiermarting.es>
 */
class Modelo130 extends Controller
{
    /** @var string */
    public $activeTab = '';

    /** bool */
    public $applyGastosJustificacion = false;

    /** @var float */
    public $gastosJustificacionPct = 5.0;

    /** @var string */
    public $codejercicio;

    /** @var string */
    public $period = 'T1';

    /** @var array */
    public $result = [];

    /** float */
    public $todeduct = 20;

    /**
     * @param int|null $idempresa
     * @return Ejercicio[]
     */
    public function getAllExercises(?int $idempresa): array
    {
        if (empty($idempresa)) {
            return Ejercicios::all();
        }

        $list = [];
        foreach (Ejercicios::all() as $exercise) {
            if ($exercise->idempresa === $idempresa) {
                $list[] = $exercise;
            }
        }

        return $list;
    }

    /**
     * @param string|null $codejercicio
     * @return Ejercicio
     */
    public function getExercise(?string $codejercicio): Ejercicio
    {
        $exercise = new Ejercicio();
        $exercise->load($this->codejercicio);
        return $exercise;
    }


    public function getAccountRules(string $type): array
    {
        $rules = DinModelo130Config::rules($type);
        foreach ($rules as $rule) {
            $rule->display_description = DinModelo130Config::description($rule->codigo);
        }
        return $rules;
    }

    public function getExpenseRules(): array
    {
        return $this->getAccountRules(Mod130Conf::TIPO_GASTO);
    }

    public function getIncomeRules(): array
    {
        return $this->getAccountRules(Mod130Conf::TIPO_INGRESO);
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'reports';
        $data['submenu'] = 'tax-models';
        $data['title'] = 'model-130';
        $data['icon'] = 'fa-solid fa-book';
        return $data;
    }

    public function getPaymentMethods(): array
    {
        return FormaPago::all();
    }

    public function getPeriodsForComboBoxHtml(): array
    {
        return [
            'T1' => 'first-trimester',
            'T2' => 'second-trimester',
            'T3' => 'third-trimester',
            'T4' => 'fourth-trimester'
        ];
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $action = $this->request->request->get('action', $this->request->input('action'));
        switch ($action) {
            case 'autocomplete-account-prefix':
                $this->autocompleteAccountPrefix();
                return;

            case 'add-account-prefix':
                $this->addAccountPrefix();
                break;

            case 'delete-account-prefix':
                $this->deleteAccountPrefix();
                break;

            case 'restore-account-prefixes':
                $this->restoreAccountPrefixes();
                break;

            case 'gen-accounting':
                $this->createAccountingEntry();
                return;
        }

        DinModelo130Config::ensureDefaults();

        $this->codejercicio = $this->request->request->get('codejercicio', '');
        $this->period = $this->request->request->get('period', $this->period);
        $this->applyGastosJustificacion = (bool)$this->request->request->get(
            'applyGastosJustificacion',
            false
        );
        $this->todeduct = (float)$this->request->request->get('todeduct', 20.0);
        $this->gastosJustificacionPct = (float)$this->request->request->get(
            'gastosJustificacionPct',
            5.0
        );

        $this->result = DinModelo130::generate(
            $this->codejercicio,
            $this->period,
            $this->applyGastosJustificacion,
            $this->todeduct,
            $this->gastosJustificacionPct
        );

        if ($action === 'download') {
            $this->downloadFile($response);
        }
    }


    protected function addAccountPrefix(): void
    {
        $type = (string) $this->request->request->get('tipo');
        $this->activeTab = $type === Mod130Conf::TIPO_INGRESO ? 'income-accounts' : 'expense-accounts';

        if (false === $this->validateFormToken()) {
            return;
        }

        $code = trim((string) $this->request->request->get('codigo'));
        if (DinModelo130Config::hasOverlap($code)) {
            Tools::log()->warning('model-130-overlapping-prefix');
            return;
        }

        $rule = new Mod130Conf();
        $rule->codigo = $code;
        $rule->tipo = $type;
        if (false === $rule->save()) {
            Tools::log()->error('record-save-error');
            return;
        }

        Tools::log()->notice('record-updated-correctly');
    }

    protected function autocompleteAccountPrefix(): void
    {
        $this->setTemplate(false);

        $type = (string) $this->request->get('tipo');
        $firstDigit = $type === Mod130Conf::TIPO_INGRESO ? '7' : '6';
        $term = trim((string) $this->request->get('term'));
        $safeTerm = $this->dataBase->var2str($term . '%');
        $safeGroup = $this->dataBase->var2str($firstDigit . '%');

        $sql = 'SELECT DISTINCT codcuenta, descripcion FROM cuentas'
            . ' WHERE codcuenta LIKE ' . $safeGroup
            . ' AND (codcuenta LIKE ' . $safeTerm
            . ' OR descripcion LIKE ' . $this->dataBase->var2str('%' . $term . '%') . ')'
            . ' ORDER BY codcuenta ASC';

        $list = [];
        foreach ($this->dataBase->selectLimit($sql, 50) as $row) {
            $code = trim((string) $row['codcuenta']);
            if ($code === '' || !ctype_digit($code)) {
                continue;
            }
            $list[] = [
                'key' => Tools::fixHtml($code),
                'value' => Tools::fixHtml($code . ' · ' . $row['descripcion'])
            ];
        }

        if (empty($list)) {
            $list[] = ['key' => null, 'value' => Tools::lang()->trans('no-data')];
        }

        $this->response->setContent(json_encode($list));
    }

    protected function deleteAccountPrefix(): void
    {
        $type = (string) $this->request->request->get('tipo');
        $this->activeTab = $type === Mod130Conf::TIPO_INGRESO ? 'income-accounts' : 'expense-accounts';

        if (false === $this->validateFormToken()) {
            return;
        }

        $rule = new Mod130Conf();
        if (false === $rule->load((int) $this->request->request->get('id'))) {
            Tools::log()->error('record-not-found');
            return;
        }

        if (false === $rule->delete()) {
            Tools::log()->error('record-deleted-error');
            return;
        }

        Tools::log()->notice('record-deleted-correctly');
    }

    protected function restoreAccountPrefixes(): void
    {
        $this->activeTab = (string) $this->request->request->get('tab', 'expense-accounts');
        if (false === $this->validateFormToken()) {
            return;
        }

        if (DinModelo130Config::restoreDefaults()) {
            Tools::log()->notice('model-130-defaults-restored');
            return;
        }
        Tools::log()->error('record-save-error');
    }

    protected function downloadFile(Response $response): void
    {
        if (empty($this->result)) {
            Tools::log()->warning('no-data');
            return;
        }

        $empresa = new Empresa();
        if (false === $empresa->load($this->result['exercise']->idempresa)) {
            Tools::log()->error('company-not-found');
            return;
        }

        // validamos los datos mínimos para no generar un fichero corrupto
        $nif = DinModelo130Export::formatNif($empresa->cifnif ?? '');
        if (strlen($nif) !== 9) {
            Tools::log()->error('aeat-file-invalid-nif');
            return;
        }

        if (empty(trim($empresa->nombre ?? ''))) {
            Tools::log()->error('aeat-file-missing-company-name');
            return;
        }

        $year = date('Y', strtotime($this->result['exercise']->fechainicio));
        if (strlen($year) !== 4) {
            Tools::log()->error('aeat-file-invalid-exercise');
            return;
        }

        $content = DinModelo130Export::generate(
            $this->result,
            $empresa,
            $this->period,
            $year
        );

        if (strlen($content) !== DinModelo130Export::FILE_LENGTH) {
            Tools::log()->error('aeat-file-invalid-length');
            return;
        }

        $filename = 'modelo130_'
            . $year
            . '_'
            . DinModelo130Export::getPeriodNumber($this->period)
            . '.txt';

        $response->headers->set(
            'Content-Type',
            'text/plain; charset=ISO-8859-1'
        );

        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="' . $filename . '"'
        );

        $response->setContent(
            mb_convert_encoding($content, 'ISO-8859-1', 'UTF-8')
        );

        $response->send();
        exit;
    }

    protected function createAccountingEntry(): void
    {
        if (false === $this->validateFormToken()) {
            return;
        }

        $idempresa = (int)$this->request->request->get('idempresa');
        $codejercicio = (string)$this->request->request->get('codejercicio');
        $period = (string)$this->request->request->get('period');
        $date = (string)$this->request->request->get('date');
        $amount = (float)$this->request->request->get('amount');
        $paymentMethodId = (int)$this->request->request->get('paymentMethod');

        if (
            DinModelo130::generateEntries(
                $idempresa,
                $codejercicio,
                $period,
                $date,
                $amount,
                $paymentMethodId
            )
        ) {
            Tools::log()->notice('record-updated-correctly');
        }
    }
}