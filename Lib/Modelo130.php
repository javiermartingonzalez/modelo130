<?php
/**
 * This file is part of Modelo130 plugin for FacturaScripts
 * Copyright (C) 2026 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\Partida;

/**
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class Modelo130
{
    /**
     * Límite anual deducible por gastos de difícil justificación
     * (art. 30.2.4ª LIRPF).
     */
    const LIMITE_GASTOS_JUSTIFICACION = 2000.0;

    /**
     * Partidas contables que intervienen en el cálculo y que no están
     * asociadas a ninguna factura.
     *
     * Cada elemento contiene:
     *
     * - entry: asiento contable.
     * - partida: partida contable.
     * - type: income, expense o previous-payment.
     * - amount: importe computado.
     *
     * @var array[]
     */
    protected static $accountingEntries = [];

    /** @var DataBase */
    protected static $dataBase;

    /** @var string */
    protected static $dateEnd = '';

    /** @var string */
    protected static $dateStart = '';

    /** @var Ejercicio */
    protected static $exercise;

    /** @var int|null */
    protected static $idempresa;

    /** @var string */
    protected static $period = '';


    /**
     * Asientos asociados a facturas de proveedor que contienen partidas
     * computables como gasto.
     *
     * @var array[]
     */
    protected static $purchases = [];

    /**
     * Asientos asociados a facturas de cliente que contienen partidas
     * computables como ingreso o retenciones en la cuenta 473.
     *
     * @var array[]
     */
    protected static $sales = [];

    /** @var float */
    protected static $taxbaseExpenses = 0.0;

    /** @var float */
    protected static $taxbaseIncomes = 0.0;

    /** @var float */
    protected static $taxbaseRetentions = 0.0;

    /** @var float */
    protected static $previousPayments = 0.0;

    /** @var bool */
    protected static $currentPaymentEntryExists = false;

    public static function generate(
        string $codejercicio,
        string $period,
        bool $applyGastosJustificacion = false,
        float $todeduct = 20.0,
        float $gastosJustificacionPct = 5.0
    ): array {
        static::$exercise = new Ejercicio();
        if (false === static::$exercise->load($codejercicio)) {
            return [];
        }

        static::$dataBase = new DataBase();
        static::$period = strtoupper($period);

        static::$accountingEntries = [];
        static::$purchases = [];
        static::$sales = [];

        static::$taxbaseExpenses = 0.0;
        static::$taxbaseIncomes = 0.0;
        static::$taxbaseRetentions = 0.0;
        static::$previousPayments = 0.0;
        static::$currentPaymentEntryExists = false;

        static::loadDates();
        static::loadAccountingData();

        $results = static::loadResults(
            $applyGastosJustificacion,
            $todeduct,
            $gastosJustificacionPct
        );

        return array_merge([
            'exercise' => static::$exercise,
            'period' => static::$period,
            'idempresa' => static::$idempresa,
            'sales' => static::$sales,
            'purchases' => static::$purchases,
            'accountingEntries' => static::$accountingEntries,
            'currentPaymentEntryExists' => static::$currentPaymentEntryExists,
            'applyGastosJustificacion' => $applyGastosJustificacion,
            'todeduct' => $todeduct,
            'gastosJustificacionPct' => $gastosJustificacionPct,
        ], $results);
    }

    public static function generateEntries(
        int $idempresa,
        string $codejercicio,
        string $period,
        string $date,
        float $amount,
        ?string $paymentMethodId
    ): bool {
        $asiento = new Asiento();
        $concepto = Tools::trans(
            'acc-concept-irpf-130',
            ['%period%' => $period]
        );

        // si ya existe un asiento igual, no lo creamos
        if ($asiento->loadWhere([
            Where::eq('codejercicio', $codejercicio),
            Where::eq('concepto', $concepto),
        ])) {
            Tools::log()->warning('exists-accounting-130', [
                '%codejercicio%' => $codejercicio,
                '%concepto%' => $concepto,
            ]);

            return false;
        }

        $asiento->idempresa = $idempresa;
        $asiento->codejercicio = $codejercicio;
        $asiento->concepto = $concepto;
        $asiento->fecha = $date;
        $asiento->importe = $amount;

        if (false === $asiento->save()) {
            return false;
        }

        $partida1 = new Partida();
        $partida1->idasiento = $asiento->idasiento;
        $partida1->concepto = $asiento->concepto;
        $partida1->debe = $amount;
        $partida1->codsubcuenta = '4730000000';

        if (false === $partida1->save()) {
            $asiento->delete();
            return false;
        }

        $bankAccount = null;
        $paymentMethod = new FormaPago();

        if ($paymentMethod->load($paymentMethodId)) {
            $bankAccount = $paymentMethod->getBankAccount();
        }

        $partida2 = new Partida();
        $partida2->idasiento = $asiento->idasiento;
        $partida2->concepto = $asiento->concepto;
        $partida2->haber = $amount;
        $partida2->codsubcuenta = !empty($bankAccount->codsubcuenta)
            ? $bankAccount->codsubcuenta
            : '5720000000';

        if (false === $partida2->save()) {
            $asiento->delete();
            return false;
        }

        Tools::log()->notice('accounting-created-130', [
            '%codejercicio%' => $codejercicio,
            '%concepto%' => $concepto,
        ]);

        return true;
    }

    /**
     * Calcula la deducción por gastos de difícil justificación aplicando el
     * porcentaje indicado sobre la base y topándola al límite anual (2.000 €).
     */
    public static function calcGastosJustificacion(
        float $taxbase,
        bool $apply,
        float $gastosJustificacionPct = 5.0
    ): float {
        if (false === $apply || $taxbase <= 0) {
            return 0.0;
        }

        $importe = round(
            $taxbase * ($gastosJustificacionPct / 100),
            2
        );

        // la deducción no puede superar el límite anual (2.000 €)
        return min(
            $importe,
            static::LIMITE_GASTOS_JUSTIFICACION
        );
    }

    /**
     * Calcula la casilla 04 (20% del rendimiento neto tras deducir los gastos
     * de difícil justificación). Si el rendimiento neto acumulado es negativo
     * (pérdidas de trimestres anteriores del mismo ejercicio), la casilla no
     * puede ser negativa.
     */
    public static function calcAfterDeduct(
        float $taxbase,
        float $gastosJustificacion,
        float $todeduct
    ): float {
        $baseDeducible = max(
            0.0,
            $taxbase - $gastosJustificacion
        );

        return round(
            ($baseDeducible * $todeduct) / 100,
            2
        );
    }


    /**
     * Calcula el pago fraccionado previo del trimestre (casilla 07) 
     * restando retenciones e ingresos de trimestres anteriores.
     * Si el resultado es negativo, el pago fraccionado no puede ser negativo.
     */
    public static function calcFractionalPayment(
        float $afterdeduct,
        float $taxbaseRetenciones,
        float $positivosTrimestres
    ): float {
        return round(
            $afterdeduct
            - $taxbaseRetenciones
            - $positivosTrimestres,
            2
        );
    }

     /**
     * Calcula el resultado final del modelo (casilla 19) en base al pago fraccionado previo.
     * Si el resultado es negativo, el resultado final no puede ser negativo.
     */
    public static function calcResult(float $fractionalPayment): float
    {
        return max(0.0, $fractionalPayment);
    }

    protected static function getSqlValueCondition(
        string $field,
        $value
    ): string {
        if (null === $value) {
            return $field . ' IS NULL';
        }

        return $field . ' = ' . static::$dataBase->var2str($value);
    }

    /**
     * Carga los datos utilizados por el modelo directamente desde las
     * partidas contables.
     *
     * Las facturas únicamente se utilizan para clasificar visualmente el
     * asiento en las pestañas Ventas o Compras y para distinguir si un
     * movimiento de la cuenta 473 corresponde a una retención.
     *
     * Los importes de ingresos, gastos e IRPF se obtienen siempre de las
     * partidas del asiento, no de los totales almacenados en la factura.
     */
    protected static function loadAccountingData(): void
    {
        $conditions = [];
    
        foreach (Modelo130Accounts::queryPrefixes() as $prefix) {
            $conditions[] = 'p.codsubcuenta LIKE '
                . static::$dataBase->var2str($prefix . '%');
        }
    
        if (empty($conditions)) {
            return;
        }
    
        $sql = 'SELECT p.*'
            . ' FROM ' . Partida::tableName() . ' p'
            . ' INNER JOIN ' . Asiento::tableName() . ' a'
            . ' ON p.idasiento = a.idasiento'
            . ' WHERE '
            . static::getSqlValueCondition(
                'a.idempresa',
                static::$idempresa
            )
            . ' AND a.fecha BETWEEN '
            . static::$dataBase->var2str(
                date('Y-m-d', strtotime(static::$dateStart))
            )
            . ' AND '
            . static::$dataBase->var2str(
                date('Y-m-d', strtotime(static::$dateEnd))
            )
            . ' AND a.operacion IS '
            . static::$dataBase->var2str(
                Asiento::OPERATION_GENERAL
            )
            . ' AND (' . implode(' OR ', $conditions) . ')'
            . ' ORDER BY a.fecha ASC, a.numero ASC, p.orden ASC';
    
        /**
         * Agrupamos las partidas por asiento.
         *
         * De esta forma una factura con varias partidas de gasto o ingreso
         * aparece una única vez en la pestaña correspondiente.
         */
        $groups = [];
    
        /**
         * Las cuentas 61 y 71 no pueden clasificarse hasta conocer su saldo
         * acumulado completo:
         *
         * - saldo deudor: gasto;
         * - saldo acreedor: ingreso.
         */
        $stockVariationBalances = [
            '61' => 0.0,
            '71' => 0.0,
        ];
    
        /**
         * Posiciones de las partidas 61 y 71 pendientes de clasificación.
         *
         * Solo estas partidas se recorren de nuevo al terminar la consulta.
         *
         * @var array<int, array{
         *     idasiento: int,
         *     entryIndex: int,
         *     prefix: string
         * }>
         */
        $pendingStockVariations = [];
    
        foreach (static::$dataBase->select($sql) as $row) {
            $partida = new Partida($row);
            $idasiento = (int)$partida->idasiento;
    
            if (!isset($groups[$idasiento])) {
                $groups[$idasiento] = [
                    'entries' => [],
                    'income' => 0.0,
                    'expense' => 0.0,
                    'retention' => 0.0,
                ];
            }
    
            $code = trim((string)$partida->codsubcuenta);
            $debit = (float)$partida->debe;
            $credit = (float)$partida->haber;
    
            $income = 0.0;
            $expense = 0.0;
    
            $isStockVariation = Modelo130Accounts::isStockVariation($code);
    
            if ($isStockVariation) {
                /**
                 * Acumulamos el saldo, pero aplazamos la clasificación hasta
                 * haber leído todas las partidas del período.
                 */
                $prefix = substr($code, 0, 2);
    
                if (isset($stockVariationBalances[$prefix])) {
                    $stockVariationBalances[$prefix] += $debit - $credit;
                }
            } else {
                /**
                 * En ingresos sumamos haber - debe.
                 *
                 * Así una rectificación o un asiento inverso reduce el ingreso
                 * computable en lugar de incrementarlo.
                 */
                if (Modelo130Accounts::isIncome($code)) {
                    $income = round($credit - $debit, 2);
                }
    
                /**
                 * En gastos sumamos debe - haber.
                 *
                 * Así una devolución o regularización inversa reduce el gasto.
                 */
                if (Modelo130Accounts::isExpense($code)) {
                    $expense = round($debit - $credit, 2);
                }
            }
    
            /**
             * En la cuenta 473 el movimiento habitual está en el debe.
             *
             * Puede ser una retención de factura o un pago fraccionado del
             * Modelo 130. La diferencia se determina después comprobando si
             * el asiento está asociado a una factura de cliente.
             */
            $retention = Modelo130Accounts::isWithholding($code)
                ? round($debit - $credit, 2)
                : 0.0;
    
            $groups[$idasiento]['income'] += $income;
            $groups[$idasiento]['expense'] += $expense;
            $groups[$idasiento]['retention'] += $retention;
    
            $entryIndex = count($groups[$idasiento]['entries']);
    
            $groups[$idasiento]['entries'][] = [
                'partida' => $partida,
                'income' => $income,
                'expense' => $expense,
                'retention' => $retention,
            ];
    
            if ($isStockVariation) {
                $prefix = substr($code, 0, 2);
    
                if (isset($stockVariationBalances[$prefix])) {
                    $pendingStockVariations[] = [
                        'idasiento' => $idasiento,
                        'entryIndex' => $entryIndex,
                        'prefix' => $prefix,
                    ];
                }
            }
        }
    
        /**
         * Clasificamos únicamente las partidas 61 y 71 pendientes.
         */
        foreach ($pendingStockVariations as $pending) {
            $idasiento = $pending['idasiento'];
            $entryIndex = $pending['entryIndex'];
            $prefix = $pending['prefix'];
    
            $balance = round(
                $stockVariationBalances[$prefix] ?? 0.0,
                2
            );
    
            if ($balance == 0.0) {
                continue;
            }
    
            $partida = $groups[$idasiento]['entries'][$entryIndex]['partida'];
            $debit = (float)$partida->debe;
            $credit = (float)$partida->haber;
    
            $income = 0.0;
            $expense = 0.0;
    
            if ($balance > 0.0) {
                /**
                 * Saldo deudor: gasto.
                 *
                 * Las partidas acreedoras producen importes negativos y
                 * reducen correctamente el gasto acumulado.
                 */
                $expense = round($debit - $credit, 2);
            } else {
                /**
                 * Saldo acreedor: ingreso.
                 *
                 * Las partidas deudoras producen importes negativos y
                 * reducen correctamente el ingreso acumulado.
                 */
                $income = round($credit - $debit, 2);
            }
    
            $groups[$idasiento]['income'] += $income;
            $groups[$idasiento]['expense'] += $expense;
    
            $groups[$idasiento]['entries'][$entryIndex]['income'] = $income;
            $groups[$idasiento]['entries'][$entryIndex]['expense'] = $expense;
        }
    
        if (empty($groups)) {
            return;
        }
    
        $entryIds = array_keys($groups);
    
        /**
         * Cargamos en bloque los asientos y las facturas relacionadas para no
         * ejecutar una consulta adicional por cada partida.
         */
        $accountingEntries = static::loadEntriesByIds($entryIds);
    
        $customerInvoices = static::loadInvoicesByEntries(
            new FacturaCliente(),
            $entryIds
        );
    
        $supplierInvoices = static::loadInvoicesByEntries(
            new FacturaProveedor(),
            $entryIds
        );

        $currentPaymentConcept = Tools::trans(
            'acc-concept-irpf-130',
            ['%period%' => static::$period]
        );
    
        foreach ($groups as $idasiento => $group) {
            $entry = $accountingEntries[$idasiento] ?? null;
    
            if (null === $entry) {
                continue;
            }

            $isCurrentPaymentEntry = $entry->concepto === $currentPaymentConcept;
            if ($isCurrentPaymentEntry) {
                static::$currentPaymentEntryExists = true;
            }
    
            $customerInvoice = $customerInvoices[$idasiento] ?? null;
            $supplierInvoice = $supplierInvoices[$idasiento] ?? null;
    
            static::$taxbaseIncomes += $group['income'];
            static::$taxbaseExpenses += $group['expense'];
    
            /**
             * Factura de cliente.
             *
             * El ingreso y el IRPF se obtienen desde las partidas contables,
             * pero se muestran agrupados utilizando los datos identificativos
             * de la factura.
             */
            if ($customerInvoice) {
                static::$taxbaseRetentions += $group['retention'];
    
                if (
                    $group['income'] != 0.0
                    || $group['retention'] != 0.0
                ) {
                    static::$sales[] = [
                        'invoice' => $customerInvoice,
                        'entry' => $entry,
                        'serie' => $customerInvoice->codserie ?? '',
                        'factura' => $customerInvoice->numero ?? '',
                        'documento' => $customerInvoice->codigo ?? '',
                        'fecha' => $entry->fecha,
                        'concepto' => $entry->concepto,
                        'baseimponible' => round(
                            $group['income'],
                            2
                        ),
                        'irpf' => round(
                            $group['retention'],
                            2
                        ),
                    ];
                }
    
                continue;
            }
    
            /**
             * Factura de proveedor.
             *
             * Únicamente aparece si el asiento contiene alguna partida
             * considerada gasto.
             *
             * Una factura contabilizada íntegramente contra una cuenta 21X no
             * aparecerá ni se deducirá. En una factura mixta únicamente se
             * computará la parte contabilizada en cuentas de gasto.
             */
            if ($supplierInvoice) {
                if ($group['expense'] != 0.0) {
                    static::$purchases[] = [
                        'invoice' => $supplierInvoice,
                        'entry' => $entry,
                        'serie' => $supplierInvoice->codserie ?? '',
                        'factura' => $supplierInvoice->numero ?? '',
                        'documento' => $supplierInvoice->codigo ?? '',
                        'fecha' => $entry->fecha,
                        'concepto' => $entry->concepto,
                        'baseimponible' => round(
                            $group['expense'],
                            2
                        ),
                        'irpf' => 0.0,
                    ];
                }
    
                continue;
            }
    
            /**
             * El asiento no está asociado a ninguna factura.
             *
             * Sus partidas se muestran en la pestaña Asientos:
             *
             * - amortizaciones;
             * - Seguridad Social;
             * - gastos manuales;
             * - ingresos manuales;
             * - variaciones de existencias;
             * - pagos fraccionados de trimestres anteriores.
             */
            foreach ($group['entries'] as $item) {
                $type = '';
                $amount = 0.0;
    
                if ($item['income'] != 0.0) {
                    $type = 'income';
                    $amount = $item['income'];
                } elseif ($item['expense'] != 0.0) {
                    $type = 'expense';
                    $amount = $item['expense'];
                } elseif ($item['retention'] != 0.0) {
                    /**
                     * Toda partida de la cuenta 473 sin factura asociada se considera
                     * un pago fraccionado del Modelo 130.
                     *
                     * El pago correspondiente al propio trimestre se detecta, pero no
                     * se incluye en la casilla 05 para garantizar la idempotencia.
                     */
                    if ($isCurrentPaymentEntry) {
                        continue;
                    }

                    $type = 'previous-payment';
                    $amount = $item['retention'];
    
                    static::$previousPayments += $amount;
                }
    
                if ($type === '') {
                    continue;
                }
    
                static::$accountingEntries[] = [
                    'entry' => $entry,
                    'partida' => $item['partida'],
                    'type' => $type,
                    'amount' => round($amount, 2),
                ];
            }
        }
    
        static::$taxbaseIncomes = round(
            static::$taxbaseIncomes,
            2
        );
    
        static::$taxbaseExpenses = round(
            static::$taxbaseExpenses,
            2
        );
    
        static::$taxbaseRetentions = round(
            static::$taxbaseRetentions,
            2
        );
    
        static::$previousPayments = round(
            static::$previousPayments,
            2
        );
    }

    protected static function loadDates(): void
    {
        if (!in_array(
            static::$period,
            ['T1', 'T2', 'T3', 'T4']
        )) {
            static::$period = 'T1';
        }

        $year = date(
            'Y',
            strtotime(static::$exercise->fechainicio)
        );

        /**
         * El Modelo 130 es acumulativo.
         *
         * Todos los períodos comienzan el 1 de enero y terminan el último día
         * del trimestre seleccionado.
         */
        static::$dateStart = '01-01-' . $year;

        switch (static::$period) {
            case 'T1':
                static::$dateEnd = '31-03-' . $year;
                break;
        
            case 'T2':
                static::$dateEnd = '30-06-' . $year;
                break;
        
            case 'T3':
                static::$dateEnd = '30-09-' . $year;
                break;
        
            default:
                static::$dateEnd = '31-12-' . $year;
                break;
        }

        static::$idempresa = static::$exercise->idempresa;
    }

    /**
     * Carga los asientos indicados y devuelve un mapa indexado por idasiento.
     *
     * @param int[] $entryIds
     * @return Asiento[]
     */
    protected static function loadEntriesByIds(
        array $entryIds
    ): array {
        if (empty($entryIds)) {
            return [];
        }

        $result = [];
        $ids = implode(
            ',',
            array_map('intval', $entryIds)
        );

        $where = [
            Where::in('idasiento', $ids),
        ];

        foreach (
            (new Asiento())->all($where, [], 0, 0)
            as $entry
        ) {
            $result[(int)$entry->idasiento] = $entry;
        }

        return $result;
    }

    /**
     * Carga en una sola consulta las facturas asociadas a los asientos.
     *
     * @param FacturaCliente|FacturaProveedor $model
     * @param int[] $entryIds
     *
     * @return array<int, FacturaCliente|FacturaProveedor>
     */
    protected static function loadInvoicesByEntries(
        $model,
        array $entryIds
    ): array {
        if (empty($entryIds)) {
            return [];
        }

        $result = [];
        $ids = implode(
            ',',
            array_map('intval', $entryIds)
        );

        $where = [
            Where::in('idasiento', $ids),
        ];

        foreach (
            $model->all($where, [], 0, 0)
            as $invoice
        ) {
            if (empty($invoice->idasiento)) {
                continue;
            }

            $result[(int)$invoice->idasiento] = $invoice;
        }

        return $result;
    }

    protected static function loadResults(
        bool $applyGastosJustificacion,
        float $todeduct,
        float $gastosJustificacionPct = 5.0
    ): array {
        $taxbase = round(
            static::$taxbaseIncomes
            - static::$taxbaseExpenses,
            2
        );

        $gastosJustificacion = static::calcGastosJustificacion(
            $taxbase,
            $applyGastosJustificacion,
            $gastosJustificacionPct
        );

        $afterdeduct = static::calcAfterDeduct(
            $taxbase,
            $gastosJustificacion,
            $todeduct
        );

        $fractionalPayment = static::calcFractionalPayment(
            $afterdeduct,
            static::$taxbaseRetentions,
            static::$previousPayments
        );

        $result = static::calcResult($fractionalPayment);

        return [
            'taxbaseIngresos' => static::$taxbaseIncomes,
            'taxbaseRetenciones' => static::$taxbaseRetentions,
            'taxbaseGastos' => static::$taxbaseExpenses,
            'taxbase' => $taxbase,
            'gastosJustificacion' => $gastosJustificacion,
            'afterdeduct' => $afterdeduct,
            'positivosTrimestres' => static::$previousPayments,
            'fractionalPayment' => $fractionalPayment,
            'result' => $result,
        ];
    }
}