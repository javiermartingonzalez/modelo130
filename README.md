# Modelo130
El Modelo 130 es una declaración trimestral del Impuesto sobre la Renta de las
Personas Físicas (IRPF) en la que autónomos y empresarios en estimación
directa liquidan el pago fraccionado de este impuesto, a cuenta de la
declaración anual del año siguiente.

Este plugin para FacturaScripts calcula automáticamente el Modelo 130 **a
partir de las partidas de los asientos contables** del año en curso:
rendimiento neto acumulado, gastos de difícil justificación (con porcentaje
configurable y tope legal de 2.000 €/año), pagos fraccionados de trimestres
anteriores y retenciones soportadas. Desde el propio formulario se puede
previsualizar el resultado por trimestre, descargar el fichero para la AEAT y
generar el asiento contable de la liquidación.

- Ficha del plugin: https://facturascripts.com/plugins/modelo130

## Nombre de carpeta
Como con todos los plugins, la carpeta se debe llamar igual que el plugin. En este caso **Modelo130**.

## Cómo se usa
1. Instala el plugin desde el Panel de control → Plugins.
2. Ve a Informes → Modelo 130, selecciona el ejercicio y el trimestre, y
   pulsa en Previsualizar.
3. Revisa las cuentas configuradas en las pestañas **Cuentas de gastos** y
   **Cuentas de ingresos**. Al instalar el plugin se cargan automáticamente
   las habituales en estimación directa según el plan contable instalado; solo
   hay que tocarlas si tu contabilidad usa cuentas distintas.
4. Si el resultado es positivo, puedes generar directamente el asiento
   contable de la liquidación desde el propio formulario, y descargar el
   fichero para presentarlo en la AEAT.

## Cómo se calcula
El cálculo es **acumulativo**: todos los trimestres empiezan el 1 de enero y
terminan el último día del trimestre elegido. Al elegir el 3T se ven, por
tanto, los importes sumados de 1T, 2T y 3T.

Los importes salen **siempre de las partidas de los asientos contables**, no
de los totales guardados en las facturas. Las facturas solo se usan para
mostrar a qué documento corresponde cada asiento en las pestañas Ventas y
Compras, y para distinguir una retención de un pago fraccionado.

- **Casilla 01, ingresos computables**: saldo acreedor de las cuentas de
  ingreso configuradas (por defecto 70, 71, 73, 74, 75, 76, 778, 793 y 794).
- **Casilla 02, gastos deducibles**: saldo deudor de las cuentas de gasto
  configuradas (por defecto 60, 61, 62, 631, 634, 636, 639, 64, 65, 66, 675,
  678, 68, 693, 694, 695, 697 y 699), más los gastos de difícil justificación
  si están activados.
- **Casilla 05, pagos fraccionados de trimestres anteriores**: movimientos de
  la cuenta de retenciones y pagos a cuenta (473 en el plan español) **sin
  factura asociada**.
- **Casilla 06, retenciones soportadas**: movimientos de esa misma cuenta
  **con factura de cliente asociada**.

Los códigos configurados se interpretan como **prefijos**: la regla `62`
incluye cualquier subcuenta que empiece por 62. Las cuentas de gasto deben
empezar por 6 y las de ingreso por 7. La cuenta de retenciones se resuelve
sola desde la cuenta marcada como especial *IRPF* en el plan contable y no
aparece en las listas de configuración.

Los asientos de **apertura, cierre y regularización** quedan fuera del
cálculo, ya que el de cierre traspasa los grupos 6 y 7 a la cuenta 129 y
duplicaría el resultado.

### Variaciones de existencias
Las cuentas 61 y 71 no son un gasto o un ingreso fijo: se clasifican según su
**saldo acumulado del año**. Si el saldo es deudor van a la casilla 02 y si es
acreedor a la casilla 01.

### Bienes de inversión y amortizaciones
Esta es la regla que más dudas genera, así que conviene tenerla clara:

- **La factura de compra de un bien de inversión no se deduce en el
  trimestre.** Si la factura se contabiliza en una cuenta de inmovilizado
  (grupo 21, 22…), no computa como gasto y **no aparece en la pestaña
  Compras**. Es el comportamiento correcto: un bien de inversión no es un
  gasto del período, se amortiza a lo largo de su vida útil.
- **Lo deducible es la dotación a la amortización**, que se registra en las
  cuentas 68X. Ese asiento sí computa como gasto y se ve en la pestaña
  Asientos. La contrapartida, la amortización acumulada (281X y similares),
  no computa: es una cuenta del grupo 2 y sumarla duplicaría el gasto.
- En una **factura mixta** (parte a inmovilizado y parte a cuentas de gasto)
  solo computa la parte llevada a cuentas de gasto.

Si necesitas ayuda con las amortizaciones, el plugin
[Amortizaciones](https://facturascripts.com/plugins/amortizaciones) genera esos
asientos automáticamente; el Modelo 130 los recoge sin configuración adicional,
al leerlos de la contabilidad.

### Requisitos para que el cálculo sea correcto
- **Las facturas deben estar contabilizadas.** Una factura sin asiento no
  computa. El formulario avisa cuando encuentra facturas del período sin
  contabilizar para que puedas revisarlas antes de presentar la declaración.
- **El asiento de la retención debe estar vinculado a la factura de cliente**
  para que el importe vaya a la casilla 06. Si se registra a mano en la cuenta
  473 sin factura asociada, se interpreta como un pago fraccionado de un
  trimestre anterior (casilla 05). El resultado final es el mismo, pero las
  casillas 05 y 06 del fichero de la AEAT quedarían cruzadas, y Hacienda
  contrasta la casilla 06 con el modelo 190.
- **Los ajustes contables se tienen en cuenta automáticamente.** Al leer las
  partidas, cualquier asiento manual que afecte a cuentas de los grupos 6 y 7
  (amortizaciones, variaciones de existencias, regularizaciones, provisiones,
  gastos sin factura como la cuota de autónomos) entra en el cálculo sin
  configuración adicional.

### Gastos de difícil justificación
El porcentaje es configurable y por defecto es del **5%** sobre el rendimiento
neto positivo, con un tope de **2.000 € al año** (art. 30.2.4ª LIRPF). El 7%
fue una medida excepcional de los ejercicios 2023 y 2024, por lo que sigue
disponible cambiando el porcentaje a mano.

### El asiento de la liquidación
El asiento que genera el plugin se marca internamente con el identificador del
trimestre en el campo *documento* (`M130-T1`, `M130-T2`…), de forma que:

- no se puede crear dos veces el asiento del mismo trimestre;
- el asiento del propio trimestre **no reduce el resultado de ese trimestre**,
  así que puedes recalcular el modelo tantas veces como quieras y el importe
  no cambia;
- en los trimestres siguientes sí se cuenta como pago fraccionado anterior.

## Novedades y documentación
- [Cambios principales de Modelo130 v4](https://facturascripts.com/publicaciones/cambios-principales-de-modelo130-v4) — porcentaje de gastos de difícil justificación configurable y formulario reagrupado por bloques.
- [Crear informe de autoliquidación trimestral](https://facturascripts.com/publicaciones/crear-informe-de-autoliquidacion-trimestral-modelo-130) — guía paso a paso para generar el informe del trimestre.
- [Cómo rellenar el Modelo 130 con ejemplos](https://tuspapelesautonomos.es/modelo-130-como-se-calcula-descubrelo-facil-con-ejemplos/) — explicación de cada casilla del modelo oficial de la AEAT con ejemplos prácticos.
- [Gastos que te puedes deducir siendo autónomo](https://tuspapelesautonomos.es/18-gastos-que-te-puedes-deducir-siendo-autonomo/) — listado de gastos deducibles habituales para la actividad económica.
