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

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Dinamic\Model\Partida;
use FacturaScripts\Plugins\Modelo130\Lib\Modelo130;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests para la clase estática Modelo130\Lib\Modelo130.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
final class Modelo130Test extends TestCase
{
    use DefaultSettingsTrait;
    use LogErrorsTrait;

    public static function setUpBeforeClass(): void
    {
        self::setDefaultSettings();
        self::installAccountingPlan();
    }

    /**
     * Verifica que generate() devuelve un array con las claves esperadas
     * cuando se le pasa un ejercicio válido, y que devuelve [] para uno inexistente.
     */
    public function testGenerate(): void
    {
        // obtener el ejercicio más reciente disponible en BD
        $ejercicios = (new Ejercicio())->all([], ['codejercicio' => 'DESC'], 0, 1);
        $this->assertNotEmpty($ejercicios, 'No hay ningún ejercicio en la base de datos');

        $ejercicio = $ejercicios[0];
        $codejercicio = $ejercicio->codejercicio;

        // llamar al método con ejercicio y periodo válidos
        $resultado = Modelo130::generate($codejercicio, 'T1');

        // el resultado debe ser un array no vacío
        $this->assertIsArray($resultado, 'generate() debe devolver un array');
        $this->assertNotEmpty($resultado, 'generate() no debe devolver un array vacío para un ejercicio válido');

        // verificar que contiene todas las claves esperadas
        $clavesEsperadas = [
            'exercise',
            'period',
            'idempresa',
            'sales',
            'purchases',
            'accountingEntries',
            'currentPaymentEntry',
            'currentPaymentEntryExists',
            'unaccountedSales',
            'unaccountedPurchases',
            'limiteGastosJustificacion',
            'applyGastosJustificacion',
            'todeduct',
            'gastosJustificacionPct',
            'taxbaseIngresos',
            'taxbaseRetenciones',
            'taxbaseGastos',
            'taxbaseGastosTotal',
            'taxbase',
            'gastosJustificacion',
            'afterdeduct',
            'positivosTrimestres',
            'fractionalPayment',
            'result',
        ];

        foreach ($clavesEsperadas as $clave) {
            $this->assertArrayHasKey($clave, $resultado, "El resultado debe contener la clave '$clave'");
        }

        // verificar tipos concretos de las claves principales
        $this->assertInstanceOf(Ejercicio::class, $resultado['exercise'], 'exercise debe ser una instancia de Ejercicio');
        $this->assertSame('T1', $resultado['period'], 'El periodo debe ser T1');
        $this->assertIsArray($resultado['sales'], 'sales debe ser un array');
        $this->assertIsArray($resultado['purchases'], 'purchases debe ser un array');
        $this->assertIsArray($resultado['accountingEntries'], 'accountingEntries debe ser un array');

        // comprobar que el ejercicio cargado es el correcto
        $this->assertSame($codejercicio, $resultado['exercise']->codejercicio, 'El ejercicio debe coincidir con el solicitado');

        // llamar con un ejercicio que no existe: debe devolver array vacío
        $resultadoVacio = Modelo130::generate('EJERCICIO_INEXISTENTE_XYZ', 'T1');
        $this->assertIsArray($resultadoVacio, 'generate() debe devolver un array aunque el ejercicio no exista');
        $this->assertEmpty($resultadoVacio, 'generate() debe devolver [] para un ejercicio inexistente');
    }

    /**
     * Verifica que generateEntries() crea el asiento con las dos partidas,
     * y que devuelve false si se intenta crear uno duplicado.
     */
    public function testGenerateEntries(): void
    {
        // obtener el ejercicio más reciente disponible en BD
        $ejercicios = (new Ejercicio())->all([], ['codejercicio' => 'DESC'], 0, 1);
        $this->assertNotEmpty($ejercicios, 'No hay ningún ejercicio en la base de datos');

        $ejercicio = $ejercicios[0];
        $codejercicio = $ejercicio->codejercicio;
        $idempresa = $ejercicio->idempresa;

        // obtener una forma de pago existente; el identificador es codpago
        $formasPago = (new FormaPago())->all([], [], 0, 1);
        $paymentMethodId = empty($formasPago) ? null : (string)$formasPago[0]->codpago;
        $this->assertNotSame('0', $paymentMethodId, 'codpago no es un entero');

        $periodo = 'T1';
        $importe = 100.0;
        $year = date('Y', strtotime($ejercicio->fechainicio));
        $fecha = $year . '-03-31';

        // la primera llamada debe crear el asiento correctamente
        $creado = Modelo130::generateEntries($idempresa, $codejercicio, $periodo, $fecha, $importe, $paymentMethodId);
        $this->assertTrue($creado, 'generateEntries() debe devolver true al crear el asiento por primera vez');

        // buscar el asiento recién creado en BD usando el concepto generado por el método
        $concepto = Tools::trans('acc-concept-irpf-130', ['%period%' => $periodo]);
        $asiento = new Asiento();
        $encontrado = $asiento->loadWhere([
            Where::eq('codejercicio', $codejercicio),
            Where::eq('concepto', $concepto),
        ]);

        $this->assertTrue($encontrado, 'El asiento debe existir en la base de datos tras generateEntries()');
        $this->assertEquals($importe, $asiento->importe, 'El importe del asiento debe ser 100.0');

        // el asiento se marca con un documento independiente del idioma
        $this->assertSame(
            Modelo130::entryDocument($periodo),
            $asiento->documento,
            'El asiento debe llevar el identificador del trimestre en documento'
        );

        // comprobar que el asiento contiene las dos partidas esperadas
        $partidas = (new Partida())->all([
            Where::eq('idasiento', $asiento->idasiento),
        ], ['orden' => 'ASC']);

        $this->assertCount(2, $partidas, 'El asiento debe contener dos partidas');
        $this->assertSame('4730000000', $partidas[0]->codsubcuenta);
        $this->assertEquals($importe, $partidas[0]->debe);
        $this->assertEquals(0.0, (float)$partidas[0]->haber);
        $this->assertEquals($importe, $partidas[1]->haber);

        // generate() debe devolver el propio asiento para que la interfaz
        // pueda mostrar un enlace directo cuando ya existe.
        $resultado = Modelo130::generate($codejercicio, $periodo);
        $this->assertTrue($resultado['currentPaymentEntryExists']);
        $this->assertInstanceOf(Asiento::class, $resultado['currentPaymentEntry']);
        $this->assertSame(
            (int) $asiento->idasiento,
            (int) $resultado['currentPaymentEntry']->idasiento
        );
        $this->assertNotEmpty($resultado['currentPaymentEntry']->url());

        // una segunda llamada con los mismos parámetros debe devolver false (ya existe)
        $duplicado = Modelo130::generateEntries($idempresa, $codejercicio, $periodo, $fecha, $importe, $paymentMethodId);
        $this->assertFalse($duplicado, 'generateEntries() debe devolver false si ya existe el asiento');

        // limpieza: eliminar el asiento (las partidas se eliminan en cascada)
        $this->assertTrue($asiento->delete(), 'No se pudo eliminar el asiento de prueba');
    }

    /**
     * Verifica el cálculo de los gastos de difícil justificación: porcentaje
     * configurable, desactivado, bases no positivas y el tope anual de 2.000 €.
     */
    public function testCalcGastosJustificacion(): void
    {
        // desactivado: siempre 0 aunque haya base
        $this->assertSame(0.0, Modelo130::calcGastosJustificacion(10000.0, false, 5.0));

        // base cero o negativa: 0
        $this->assertSame(0.0, Modelo130::calcGastosJustificacion(0.0, true, 5.0));
        $this->assertSame(0.0, Modelo130::calcGastosJustificacion(-500.0, true, 5.0));

        // porcentaje por defecto (5%) sobre una base por debajo del límite
        $this->assertSame(500.0, Modelo130::calcGastosJustificacion(10000.0, true, 5.0));

        // el 5% anterior sigue siendo posible de forma explícita
        $this->assertSame(700.0, Modelo130::calcGastosJustificacion(10000.0, true, 7.0));

        // redondeo a 2 decimales
        $this->assertSame(50.25, Modelo130::calcGastosJustificacion(1005.0, true, 5.0));

        // tope anual de 2.000 €: 5% de 45.000 = 2.250, pero se limita a 2.000
        $this->assertSame(2000.0, Modelo130::calcGastosJustificacion(45000.0, true, 5.0));

        // muy por encima del tope: se limita igualmente a 2.000
        $this->assertSame(2000.0, Modelo130::calcGastosJustificacion(100000.0, true, 5.0));

        // justo por debajo del límite (5% de 39.900 = 1.995): no se topa
        $this->assertSame(1995.0, Modelo130::calcGastosJustificacion(39900.0, true, 5.0));

        // la constante del límite es la esperada
        $this->assertSame(2000.0, Modelo130::LIMITE_GASTOS_JUSTIFICACION);
    }

    /**
     * Verifica que la casilla 04 (20% sobre la 03) nunca es negativa, ni
     * siquiera cuando el rendimiento neto acumulado arrastra pérdidas de
     * trimestres anteriores del mismo ejercicio.
     */
    public function testCalcAfterDeduct(): void
    {
        // caso normal: 20% de 10.000 = 2.000
        $this->assertSame(2000.0, Modelo130::calcAfterDeduct(10000.0, 0.0, 20.0));

        // rendimiento neto negativo (pérdidas acumuladas): la casilla no baja de 0
        $this->assertSame(0.0, Modelo130::calcAfterDeduct(-500.0, 0.0, 20.0));

        // rendimiento neto exactamente 0: 0
        $this->assertSame(0.0, Modelo130::calcAfterDeduct(0.0, 0.0, 20.0));

        // con gastos de difícil justificación descontados
        $this->assertSame(1900.0, Modelo130::calcAfterDeduct(10000.0, 500.0, 20.0));
    }

    /**
     * Verifica que el resultado final nunca es negativo tras descontar
     * retenciones e ingresos de trimestres anteriores.
     */
    public function testCalcFractionalPayment(): void
    {
        // caso normal: 2.000 - 500 - 300 = 1.200
        $this->assertSame(1200.0, Modelo130::calcFractionalPayment(2000.0, 500.0, 300.0));

        // resultado negativo
        $this->assertSame(-200.0, Modelo130::calcFractionalPayment(500.0, 400.0, 300.0));

        // resultado exactamente 0
        $this->assertSame(0.0, Modelo130::calcFractionalPayment(100.0, 50.0, 50.0));

        // redondeo a 2 decimales
        $this->assertSame(33.33, Modelo130::calcFractionalPayment(100.005, 33.33, 33.345));
    }

    /**
     * La casilla 05 nunca puede ser negativa, aunque la cuenta de retenciones
     * quede con saldo acreedor sin factura asociada. El fichero de la AEAT
     * aplica el mismo tope, así que pantalla y fichero deben coincidir.
     */
    public function testPreviousPaymentsAreNeverNegative(): void
    {
        $ejercicios = (new Ejercicio())->all([], ['codejercicio' => 'DESC'], 0, 1);
        $this->assertNotEmpty($ejercicios, 'No hay ningún ejercicio en la base de datos');

        $resultado = Modelo130::generate($ejercicios[0]->codejercicio, 'T4');

        $this->assertGreaterThanOrEqual(0.0, $resultado['positivosTrimestres']);
    }

    /**
     * La casilla 02 que se envía a la AEAT incluye los gastos de difícil
     * justificación, por lo que el cálculo debe exponerla ya sumada.
     */
    public function testExpensesTotalIncludesGastosJustificacion(): void
    {
        $ejercicios = (new Ejercicio())->all([], ['codejercicio' => 'DESC'], 0, 1);
        $this->assertNotEmpty($ejercicios, 'No hay ningún ejercicio en la base de datos');

        $resultado = Modelo130::generate($ejercicios[0]->codejercicio, 'T4', true);

        $this->assertSame(
            round(
                $resultado['taxbaseGastos'] + $resultado['gastosJustificacion'],
                2
            ),
            $resultado['taxbaseGastosTotal']
        );
    }

    /**
     * entryDocument() normaliza el período y siempre devuelve un identificador
     * válido, aunque le llegue un valor inesperado.
     */
    public function testEntryDocument(): void
    {
        $this->assertSame('M130-T1', Modelo130::entryDocument('T1'));
        $this->assertSame('M130-T4', Modelo130::entryDocument('t4'));
        $this->assertSame('M130-T2', Modelo130::entryDocument(' T2 '));
        $this->assertSame('M130-T1', Modelo130::entryDocument('trimestre'));
    }

    /**
     * Verifica que el resultado final nunca es negativo 
     * en base al pago fraccionado previo del trimestre.
     */
    public function testCalcResult(): void
    {
        // caso normal: 1.200
        $this->assertSame(1200.0, Modelo130::calcResult(1200.0));
        // resultado negativo: la casilla no baja de 0
        $this->assertSame(0.0, Modelo130::calcResult(-200.0));
        // resultado exactamente 0
        $this->assertSame(0.0, Modelo130::calcResult(0.0));
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}