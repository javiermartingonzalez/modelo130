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

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\Cuenta;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Partida;
use FacturaScripts\Dinamic\Model\Subcuenta;
use FacturaScripts\Plugins\Modelo130\Lib\Modelo130;
use FacturaScripts\Plugins\Modelo130\Lib\Modelo130Accounts;
use FacturaScripts\Plugins\Modelo130\Lib\Modelo130Config;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use FacturaScripts\Test\Traits\RandomDataTrait;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas del cálculo del Modelo 130 a partir de las partidas contables.
 *
 * Cada prueba compara el resultado antes y después de crear los asientos, de
 * forma que no depende de los datos que ya hubiera en la base de datos.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
final class Modelo130AccountingTest extends TestCase
{
    use DefaultSettingsTrait;
    use Modelo130Fixtures;
    use LogErrorsTrait;
    use RandomDataTrait;

    /** Ingresos por ventas. */
    private const ACCOUNT_INCOME = '7000000000';

    /** Servicios exteriores. */
    private const ACCOUNT_EXPENSE = '6200000000';

    /** Amortización del inmovilizado material. */
    private const ACCOUNT_DEPRECIATION = '6810000000';

    /** Amortización acumulada del inmovilizado material. */
    private const ACCOUNT_DEPRECIATION_ACC = '2811000000';

    /** Equipos para procesos de información (inmovilizado). */
    private const ACCOUNT_FIXED_ASSET = '2160000000';

    /** Hacienda pública, retenciones y pagos a cuenta. */
    private const ACCOUNT_WITHHOLDING = '4730000000';

    /** Hacienda pública, acreedora por retenciones practicadas. */
    private const ACCOUNT_WITHHOLDING_PURCHASE = '4751000000';

    /** Impuesto sobre beneficios: no es gasto deducible del Modelo 130. */
    private const ACCOUNT_NOT_DEDUCTIBLE = '6300000000';

    /** Variación de existencias de mercaderías. */
    private const ACCOUNT_STOCK_EXPENSE = '6100000000';

    /** Variación de existencias de productos terminados. */
    private const ACCOUNT_STOCK_INCOME = '7100000000';

    /** Bancos c/c. */
    private const ACCOUNT_BANK = '5720000000';

    /** Clientes. */
    private const ACCOUNT_CUSTOMERS = '4300000000';

    /** Proveedores. */
    private const ACCOUNT_SUPPLIERS = '4000000000';

    /** @var Ejercicio|null */
    private static $exercise = null;

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
        self::ensureExerciseWithAccountingPlan();

        // partimos siempre de la configuración de cuentas predeterminada
        Modelo130Config::restoreDefaults();
        Modelo130Accounts::resetCache();
    }

    /**
     * Una factura de cliente contabilizada suma su base en ingresos y su
     * retención en la casilla 06, y aparece en la pestaña de ventas.
     */
    public function testCustomerInvoiceWithWithholding(): void
    {
        $before = $this->calculate();

        $invoice = $this->getRandomCustomerInvoice($this->date('T1'));
        $entry = $this->replaceInvoiceEntry($invoice, 'facturascli', [
            [self::ACCOUNT_INCOME, 0.0, 1000.0],
            [self::ACCOUNT_WITHHOLDING, 150.0, 0.0],
            [self::ACCOUNT_CUSTOMERS, 850.0, 0.0],
        ]);

        $after = $this->calculate();

        $this->assertSame(1000.0, $this->delta($before, $after, 'taxbaseIngresos'));
        $this->assertSame(150.0, $this->delta($before, $after, 'taxbaseRetenciones'));
        $this->assertSame(0.0, $this->delta($before, $after, 'taxbaseGastos'));
        $this->assertSame(0.0, $this->delta($before, $after, 'positivosTrimestres'));

        // la factura se muestra en ventas con los importes de las partidas
        $row = $this->findRow($after['sales'], $entry->idasiento);
        $this->assertNotNull($row, 'La factura debe aparecer en la pestaña de ventas');
        $this->assertSame(1000.0, (float)$row['baseimponible']);
        $this->assertSame(150.0, (float)$row['irpf']);
        $this->assertSame($invoice->codigo, $row['documento']);

        // y no debe aparecer en la pestaña de asientos
        $this->assertNull($this->findEntry($after['accountingEntries'], $entry->idasiento));

        $this->removeInvoice($invoice, $entry);
    }

    /**
     * Una factura de proveedor contabilizada en una cuenta de gasto suma su
     * base en la casilla 02 y aparece en la pestaña de compras.
     */
    public function testSupplierInvoiceIsDeductible(): void
    {
        $before = $this->calculate();

        $invoice = $this->getRandomSupplierInvoice($this->date('T1'));
        $entry = $this->replaceInvoiceEntry($invoice, 'facturasprov', [
            [self::ACCOUNT_EXPENSE, 500.0, 0.0],
            [self::ACCOUNT_SUPPLIERS, 0.0, 500.0],
        ]);

        $after = $this->calculate();

        $this->assertSame(500.0, $this->delta($before, $after, 'taxbaseGastos'));
        $this->assertSame(0.0, $this->delta($before, $after, 'taxbaseIngresos'));

        $row = $this->findRow($after['purchases'], $entry->idasiento);
        $this->assertNotNull($row, 'La factura debe aparecer en la pestaña de compras');
        $this->assertSame(500.0, (float)$row['baseimponible']);

        $this->removeInvoice($invoice, $entry);
    }

    /**
     * Tarea 4578: la factura de compra de un bien de inversión contabilizada en
     * el inmovilizado no es gasto del trimestre y no aparece en compras. Solo
     * es deducible su amortización.
     */
    public function testFixedAssetInvoiceIsNotDeductible(): void
    {
        $before = $this->calculate();

        $invoice = $this->getRandomSupplierInvoice($this->date('T1'));
        $entry = $this->replaceInvoiceEntry($invoice, 'facturasprov', [
            [self::ACCOUNT_FIXED_ASSET, 3000.0, 0.0],
            [self::ACCOUNT_SUPPLIERS, 0.0, 3000.0],
        ]);

        $after = $this->calculate();

        $this->assertSame(
            0.0,
            $this->delta($before, $after, 'taxbaseGastos'),
            'La compra de inmovilizado no puede deducirse en el trimestre'
        );
        $this->assertNull(
            $this->findRow($after['purchases'], $entry->idasiento),
            'La factura de inmovilizado no debe aparecer en compras'
        );

        $this->removeInvoice($invoice, $entry);
    }

    /**
     * Tarea 4578: en una factura mixta solo computa la parte contabilizada en
     * cuentas de gasto.
     */
    public function testMixedInvoiceOnlyDeductsExpensePart(): void
    {
        $before = $this->calculate();

        $invoice = $this->getRandomSupplierInvoice($this->date('T1'));
        $entry = $this->replaceInvoiceEntry($invoice, 'facturasprov', [
            [self::ACCOUNT_FIXED_ASSET, 2000.0, 0.0],
            [self::ACCOUNT_EXPENSE, 300.0, 0.0],
            [self::ACCOUNT_SUPPLIERS, 0.0, 2300.0],
        ]);

        $after = $this->calculate();

        $this->assertSame(300.0, $this->delta($before, $after, 'taxbaseGastos'));

        $row = $this->findRow($after['purchases'], $entry->idasiento);
        $this->assertNotNull($row);
        $this->assertSame(300.0, (float)$row['baseimponible']);

        $this->removeInvoice($invoice, $entry);
    }

    /**
     * Tarea 4578: la dotación a la amortización sí es gasto deducible, y la
     * amortización acumulada no computa por ser una cuenta del grupo 2.
     */
    public function testDepreciationIsDeductible(): void
    {
        $before = $this->calculate();

        $entry = $this->createEntry($this->date('T1'), 'Amortización del ejercicio', [
            [self::ACCOUNT_DEPRECIATION, 600.0, 0.0],
            [self::ACCOUNT_DEPRECIATION_ACC, 0.0, 600.0],
        ]);

        $after = $this->calculate();

        $this->assertSame(600.0, $this->delta($before, $after, 'taxbaseGastos'));
        $this->assertSame(0.0, $this->delta($before, $after, 'taxbaseIngresos'));

        // aparece una única línea en la pestaña de asientos, la de la 681
        $rows = $this->findEntries($after['accountingEntries'], $entry->idasiento);
        $this->assertCount(1, $rows);
        $this->assertSame('expense', $rows[0]['type']);
        $this->assertSame(600.0, (float)$rows[0]['amount']);
        $this->assertSame(self::ACCOUNT_DEPRECIATION, $rows[0]['partida']->codsubcuenta);

        $this->assertTrue($entry->delete());
    }

    /**
     * Un movimiento en la cuenta de retenciones sin factura asociada es un pago
     * fraccionado de un trimestre anterior (casilla 05), no una retención.
     */
    public function testWithholdingWithoutInvoiceIsPreviousPayment(): void
    {
        $before = $this->calculate();

        $entry = $this->createEntry($this->date('T2'), 'Pago del modelo 130 anterior', [
            [self::ACCOUNT_WITHHOLDING, 250.0, 0.0],
            [self::ACCOUNT_BANK, 0.0, 250.0],
        ]);

        $after = $this->calculate();

        $this->assertSame(250.0, $this->delta($before, $after, 'positivosTrimestres'));
        $this->assertSame(0.0, $this->delta($before, $after, 'taxbaseRetenciones'));

        $rows = $this->findEntries($after['accountingEntries'], $entry->idasiento);
        $this->assertCount(1, $rows);
        $this->assertSame('previous-payment', $rows[0]['type']);
        $this->assertSame(250.0, (float)$rows[0]['amount']);

        $this->assertTrue($entry->delete());
    }

    /**
     * La retención practicada a un proveedor (4751) no es una retención
     * soportada y no puede reducir el pago fraccionado.
     */
    public function testPurchaseWithholdingIsIgnored(): void
    {
        $before = $this->calculate();

        $entry = $this->createEntry($this->date('T1'), 'Retención practicada', [
            [self::ACCOUNT_EXPENSE, 1000.0, 0.0],
            [self::ACCOUNT_WITHHOLDING_PURCHASE, 0.0, 150.0],
            [self::ACCOUNT_SUPPLIERS, 0.0, 850.0],
        ]);

        $after = $this->calculate();

        $this->assertSame(1000.0, $this->delta($before, $after, 'taxbaseGastos'));
        $this->assertSame(0.0, $this->delta($before, $after, 'taxbaseRetenciones'));
        $this->assertSame(0.0, $this->delta($before, $after, 'positivosTrimestres'));

        $this->assertTrue($entry->delete());
    }

    /**
     * Una cuenta que no está en la configuración no computa, aunque sea del
     * grupo 6. Es el caso del impuesto sobre beneficios.
     */
    public function testAccountOutOfConfigurationIsIgnored(): void
    {
        $before = $this->calculate();

        $entry = $this->createEntry($this->date('T1'), 'Impuesto sobre beneficios', [
            [self::ACCOUNT_NOT_DEDUCTIBLE, 400.0, 0.0],
            [self::ACCOUNT_BANK, 0.0, 400.0],
        ]);

        $after = $this->calculate();

        $this->assertSame(0.0, $this->delta($before, $after, 'taxbaseGastos'));
        $this->assertSame(0.0, $this->delta($before, $after, 'taxbaseIngresos'));

        $this->assertTrue($entry->delete());
    }

    /**
     * Los abonos reducen el importe computado en lugar de incrementarlo: un
     * ingreso al debe resta de la casilla 01 y un gasto al haber de la 02.
     */
    public function testRefundsReduceAmounts(): void
    {
        $before = $this->calculate();

        $entry = $this->createEntry($this->date('T1'), 'Rectificativa', [
            [self::ACCOUNT_INCOME, 200.0, 0.0],
            [self::ACCOUNT_EXPENSE, 0.0, 50.0],
            [self::ACCOUNT_BANK, 0.0, 150.0],
        ]);

        $after = $this->calculate();

        $this->assertSame(-200.0, $this->delta($before, $after, 'taxbaseIngresos'));
        $this->assertSame(-50.0, $this->delta($before, $after, 'taxbaseGastos'));

        $this->assertTrue($entry->delete());
    }

    /**
     * Las variaciones de existencias se clasifican según el saldo acumulado del
     * período: saldo deudor es gasto y saldo acreedor es ingreso.
     */
    public function testStockVariationFollowsBalance(): void
    {
        // 61 con saldo deudor: gasto
        $before = $this->calculate();
        $entry = $this->createEntry($this->date('T1'), 'Variación de existencias', [
            [self::ACCOUNT_STOCK_EXPENSE, 900.0, 0.0],
            ['3000000000', 0.0, 900.0],
        ]);
        $after = $this->calculate();
        $this->assertSame(900.0, $this->delta($before, $after, 'taxbaseGastos'));
        $this->assertSame(0.0, $this->delta($before, $after, 'taxbaseIngresos'));
        $this->assertTrue($entry->delete());

        // 61 con saldo acreedor: ingreso
        $before = $this->calculate();
        $entry = $this->createEntry($this->date('T1'), 'Variación de existencias', [
            [self::ACCOUNT_STOCK_EXPENSE, 0.0, 700.0],
            ['3000000000', 700.0, 0.0],
        ]);
        $after = $this->calculate();
        $this->assertSame(700.0, $this->delta($before, $after, 'taxbaseIngresos'));
        $this->assertSame(0.0, $this->delta($before, $after, 'taxbaseGastos'));
        $this->assertTrue($entry->delete());

        // 71 con saldo acreedor: ingreso
        $before = $this->calculate();
        $entry = $this->createEntry($this->date('T1'), 'Variación de existencias', [
            [self::ACCOUNT_STOCK_INCOME, 0.0, 400.0],
            ['3500000000', 400.0, 0.0],
        ]);
        $after = $this->calculate();
        $this->assertSame(400.0, $this->delta($before, $after, 'taxbaseIngresos'));
        $this->assertTrue($entry->delete());

        // saldo compensado: no computa en ninguna casilla
        $before = $this->calculate();
        $entry = $this->createEntry($this->date('T1'), 'Variación compensada', [
            [self::ACCOUNT_STOCK_EXPENSE, 300.0, 0.0],
            [self::ACCOUNT_STOCK_EXPENSE, 0.0, 300.0],
        ]);
        $after = $this->calculate();
        $this->assertSame(0.0, $this->delta($before, $after, 'taxbaseGastos'));
        $this->assertSame(0.0, $this->delta($before, $after, 'taxbaseIngresos'));
        $this->assertTrue($entry->delete());
    }

    /**
     * Los asientos de apertura, cierre y regularización quedan fuera: el de
     * cierre traspasa los grupos 6 y 7 a la cuenta 129 y duplicaría el
     * resultado.
     */
    public function testSpecialOperationEntriesAreExcluded(): void
    {
        $operations = [
            Asiento::OPERATION_OPENING,
            Asiento::OPERATION_CLOSING,
            Asiento::OPERATION_REGULARIZATION,
        ];

        foreach ($operations as $operation) {
            $before = $this->calculate();

            $entry = $this->createEntry(
                $this->date('T1'),
                'Asiento especial',
                [
                    [self::ACCOUNT_INCOME, 5000.0, 0.0],
                    [self::ACCOUNT_EXPENSE, 0.0, 5000.0],
                ],
                $operation
            );

            $after = $this->calculate();

            $this->assertSame(
                0.0,
                $this->delta($before, $after, 'taxbaseIngresos'),
                'La operación ' . $operation . ' no debe computar'
            );
            $this->assertSame(
                0.0,
                $this->delta($before, $after, 'taxbaseGastos'),
                'La operación ' . $operation . ' no debe computar'
            );

            $this->assertTrue($entry->delete());
        }
    }

    /**
     * El modelo es acumulativo: un asiento del segundo trimestre no computa en
     * el primero, pero sí en el segundo y siguientes.
     */
    public function testPeriodsAreCumulative(): void
    {
        $beforeT1 = $this->calculate('T1');
        $beforeT2 = $this->calculate('T2');
        $beforeT3 = $this->calculate('T3');

        $entry = $this->createEntry($this->date('T2'), 'Ingreso del segundo trimestre', [
            [self::ACCOUNT_INCOME, 0.0, 800.0],
            [self::ACCOUNT_CUSTOMERS, 800.0, 0.0],
        ]);

        $this->assertSame(0.0, $this->delta($beforeT1, $this->calculate('T1'), 'taxbaseIngresos'));
        $this->assertSame(800.0, $this->delta($beforeT2, $this->calculate('T2'), 'taxbaseIngresos'));
        $this->assertSame(800.0, $this->delta($beforeT3, $this->calculate('T3'), 'taxbaseIngresos'));

        $this->assertTrue($entry->delete());
    }

    /**
     * El asiento de liquidación del propio trimestre no reduce el resultado de
     * ese trimestre, para que el cálculo sea idempotente. En el trimestre
     * siguiente sí cuenta como pago fraccionado anterior.
     */
    public function testCurrentPaymentEntryKeepsResultStable(): void
    {
        // ingreso suficiente para que el trimestre salga a pagar
        $income = $this->createEntry($this->date('T2'), 'Ingreso del trimestre', [
            [self::ACCOUNT_INCOME, 0.0, 4000.0],
            [self::ACCOUNT_CUSTOMERS, 4000.0, 0.0],
        ]);

        $before = $this->calculate('T2');
        $this->assertGreaterThan(0.0, $before['result']);
        $this->assertFalse($before['currentPaymentEntryExists']);

        $beforeT3 = $this->calculate('T3');

        // generamos el asiento de liquidación con fecha dentro del trimestre
        $this->assertTrue(Modelo130::generateEntries(
            (int)self::exercise()->idempresa,
            self::exercise()->codejercicio,
            'T2',
            $this->date('T2'),
            $before['result'],
            null
        ));

        $after = $this->calculate('T2');

        $this->assertTrue($after['currentPaymentEntryExists']);
        $this->assertSame(
            $before['result'],
            $after['result'],
            'El resultado del trimestre no puede cambiar tras generar su asiento'
        );
        $this->assertSame(
            $before['positivosTrimestres'],
            $after['positivosTrimestres']
        );

        // en el trimestre siguiente sí es un pago fraccionado anterior
        $afterT3 = $this->calculate('T3');
        $this->assertSame(
            $before['result'],
            $this->delta($beforeT3, $afterT3, 'positivosTrimestres')
        );

        // no se puede duplicar el asiento del mismo trimestre
        $this->assertFalse(Modelo130::generateEntries(
            (int)self::exercise()->idempresa,
            self::exercise()->codejercicio,
            'T2',
            $this->date('T2'),
            $before['result'],
            null
        ));

        // el asiento se identifica por el campo documento, no por el concepto
        $payment = $after['currentPaymentEntry'];
        $this->assertSame(Modelo130::entryDocument('T2'), $payment->documento);

        $payment->editable = true;
        $this->assertTrue($payment->delete());
        $this->assertTrue($income->delete());
    }

    /**
     * Las facturas sin asiento contable no computan, y el cálculo informa de
     * cuántas hay para que el usuario pueda revisarlas.
     */
    public function testUnaccountedInvoicesAreReported(): void
    {
        $before = $this->calculate();

        $invoice = $this->getRandomCustomerInvoice($this->date('T1'));
        $entry = $invoice->getAccountingEntry();

        if ($entry->exists()) {
            $entry->editable = true;
            $this->assertTrue($entry->delete());
        }

        $this->assertTrue($this->database()->exec(
            'UPDATE facturascli SET idasiento = NULL WHERE idfactura = '
            . $this->database()->var2str($invoice->idfactura) . ';'
        ));

        $after = $this->calculate();

        $this->assertSame(0.0, $this->delta($before, $after, 'taxbaseIngresos'));
        $this->assertSame(
            1,
            $after['unaccountedSales'] - $before['unaccountedSales'],
            'La factura sin contabilizar debe contarse en el aviso'
        );

        $invoice->load($invoice->idfactura);
        $this->assertTrue($invoice->delete());
    }

    /**
     * Los asientos de otra empresa no intervienen en el cálculo.
     */
    public function testOtherCompanyEntriesAreExcluded(): void
    {
        $company = $this->getRandomCompany();
        if (false === $company->save()) {
            $this->markTestSkipped('No se ha podido crear una segunda empresa.');
        }

        $before = $this->calculate();

        $entry = new Asiento();
        $entry->codejercicio = self::exercise()->codejercicio;
        $entry->idempresa = $company->idempresa;
        $entry->concepto = 'Ingreso de otra empresa';
        $entry->fecha = $this->date('T1');
        $entry->importe = 1000.0;
        $this->assertTrue($entry->save());

        $this->addLine($entry, self::ACCOUNT_INCOME, 0.0, 1000.0);
        $this->addLine($entry, self::ACCOUNT_CUSTOMERS, 1000.0, 0.0);

        $after = $this->calculate();

        $this->assertSame(0.0, $this->delta($before, $after, 'taxbaseIngresos'));

        $this->assertTrue($entry->delete());
        $this->assertTrue($company->delete());
    }

    // ------------------------------------------------------------------ //
    // Utilidades
    // ------------------------------------------------------------------ //

    /**
     * Ejecuta el cálculo del modelo para el período indicado.
     */
    private function calculate(string $period = 'T4'): array
    {
        $result = Modelo130::generate(self::exercise()->codejercicio, $period);
        $this->assertNotEmpty($result, 'El cálculo no debe devolver un array vacío');

        return $result;
    }

    /**
     * Diferencia de una casilla entre dos cálculos.
     */
    private function delta(array $before, array $after, string $key): float
    {
        return round((float)$after[$key] - (float)$before[$key], 2);
    }

    /**
     * Crea un asiento con las partidas indicadas.
     *
     * @param array<int, array{0: string, 1: float, 2: float}> $lines
     */
    private function createEntry(
        string $date,
        string $concept,
        array $lines,
        ?string $operation = null
    ): Asiento {
        $entry = new Asiento();
        $entry->codejercicio = self::exercise()->codejercicio;
        $entry->idempresa = self::exercise()->idempresa;
        $entry->concepto = $concept;
        $entry->fecha = $date;
        $entry->importe = 0.0;
        $entry->operacion = $operation;

        foreach ($lines as $line) {
            $entry->importe += $line[1];
        }

        $this->assertTrue($entry->save(), 'No se ha podido crear el asiento de prueba');

        foreach ($lines as $line) {
            $this->addLine($entry, $line[0], $line[1], $line[2]);
        }

        return $entry;
    }

    /**
     * Añade una partida al asiento, creando la subcuenta si no existe.
     */
    private function addLine(Asiento $entry, string $code, float $debit, float $credit): Partida
    {
        $line = new Partida();
        $line->idasiento = $entry->idasiento;
        $line->codsubcuenta = $this->subaccount($code);
        $line->concepto = $entry->concepto;
        $line->debe = $debit;
        $line->haber = $credit;

        $this->assertTrue(
            $line->save(),
            'No se ha podido crear la partida ' . $code
        );

        return $line;
    }

    /**
     * Sustituye el asiento generado automáticamente por la factura por uno con
     * las partidas indicadas, sin pasar por el recálculo del documento.
     *
     * @param array<int, array{0: string, 1: float, 2: float}> $lines
     */
    private function replaceInvoiceEntry($invoice, string $table, array $lines): Asiento
    {
        $old = $invoice->getAccountingEntry();
        if ($old->exists()) {
            $old->editable = true;
            $this->assertTrue($old->delete());
        }

        $entry = $this->createEntry(
            $invoice->fecha,
            'Asiento de prueba ' . $invoice->codigo,
            $lines
        );

        $this->assertTrue($this->database()->exec(
            'UPDATE ' . $table . ' SET idasiento = '
            . $this->database()->var2str($entry->idasiento)
            . ' WHERE idfactura = '
            . $this->database()->var2str($invoice->idfactura) . ';'
        ));

        return $entry;
    }

    /**
     * Elimina la factura de prueba y su asiento.
     */
    private function removeInvoice($invoice, Asiento $entry): void
    {
        $this->database()->exec(
            'UPDATE ' . $invoice->tableName() . ' SET idasiento = NULL'
            . ' WHERE idfactura = ' . $this->database()->var2str($invoice->idfactura) . ';'
        );

        $entry->editable = true;
        $this->assertTrue($entry->delete());

        $invoice->load($invoice->idfactura);
        $this->assertTrue($invoice->delete());
    }

    /**
     * Busca una fila de las pestañas de ventas o compras por su asiento.
     */
    private function findRow(array $rows, $idasiento): ?array
    {
        foreach ($rows as $row) {
            if ((int)$row['entry']->idasiento === (int)$idasiento) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Devuelve las líneas de la pestaña de asientos que pertenecen al asiento
     * indicado.
     */
    private function findEntries(array $rows, $idasiento): array
    {
        $result = [];

        foreach ($rows as $row) {
            if ((int)$row['entry']->idasiento === (int)$idasiento) {
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * Devuelve la primera línea de la pestaña de asientos del asiento indicado.
     */
    private function findEntry(array $rows, $idasiento): ?array
    {
        $rows = $this->findEntries($rows, $idasiento);

        return $rows[0] ?? null;
    }

    /**
     * Devuelve una fecha dentro del trimestre indicado y del ejercicio.
     */
    private function date(string $period): string
    {
        $year = date('Y', strtotime(self::exercise()->fechainicio));

        $days = [
            'T1' => '-02-15',
            'T2' => '-05-15',
            'T3' => '-08-15',
            'T4' => '-11-15',
        ];

        return $year . ($days[$period] ?? $days['T1']);
    }

    /**
     * Devuelve el código de la subcuenta, creándola desde su cuenta padre si el
     * plan contable instalado no la trae.
     */
    private function subaccount(string $code): string
    {
        $where = [
            Where::eq('codejercicio', self::exercise()->codejercicio),
            Where::eq('codsubcuenta', $code),
        ];

        $subaccount = new Subcuenta();
        if ($subaccount->loadWhere($where)) {
            return $subaccount->codsubcuenta;
        }

        // buscamos la cuenta padre más específica
        $parent = null;
        $accounts = Cuenta::all(
            [Where::eq('codejercicio', self::exercise()->codejercicio)],
            ['codcuenta' => 'ASC'],
            0,
            0
        );

        foreach ($accounts as $account) {
            $accountCode = (string)$account->codcuenta;

            if (false === str_starts_with($code, $accountCode)) {
                continue;
            }

            if (
                null === $parent
                || strlen($accountCode) > strlen((string)$parent->codcuenta)
            ) {
                $parent = $account;
            }
        }

        $this->assertNotNull($parent, 'No hay cuenta padre para la subcuenta ' . $code);

        $subaccount = new Subcuenta();
        $subaccount->codcuenta = $parent->codcuenta;
        $subaccount->codejercicio = self::exercise()->codejercicio;
        $subaccount->codsubcuenta = $code;
        $subaccount->descripcion = $parent->descripcion;
        $subaccount->idcuenta = $parent->idcuenta;

        $this->assertTrue(
            $subaccount->save(),
            'No se ha podido crear la subcuenta ' . $code
        );

        return $subaccount->codsubcuenta;
    }

    /**
     * Ejercicio sobre el que se realizan las pruebas.
     */
    private static function exercise(): Ejercicio
    {
        if (null === self::$exercise) {
            $list = (new Ejercicio())->all([], ['codejercicio' => 'DESC'], 0, 1);
            self::$exercise = $list[0] ?? new Ejercicio();
        }

        return self::$exercise;
    }

    private function database(): DataBase
    {
        static $db = null;

        if (null === $db) {
            $db = new DataBase();
            $db->connect();
        }

        return $db;
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
