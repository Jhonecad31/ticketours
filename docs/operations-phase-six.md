# Operación, liquidaciones e integraciones · Fase 6

## Boletos y check-in

Al confirmar, el proveedor emite un boleto por unidad de sesión o uno por reserva de recurso. El voucher muestra los QR imprimibles. Los vouchers históricos vigentes generan sus boletos al consultarlos por primera vez. El código contiene el identificador de lote, número de boleto y firma; el secreto del lote queda cifrado en la base de datos.

Propietarios y administradores pueden validar boletos. También pueden conceder el permiso a miembros activos mediante su identificador de usuario. El campo de check-in acepta el texto de un lector que funciona como teclado o un código pegado. El servidor valida en línea desde 30 minutos antes del inicio hasta el fin, sin incluir el instante de fin. Registra el acceso y devuelve el estado operativo pendiente, parcial o completo, separado de reserva y pago.

PostgreSQL bloquea el inventario de la reserva y aplica una clave única por boleto. Un reintento con la misma clave de idempotencia devuelve el primer registro. Una nueva operación sobre un boleto usado se rechaza. Cancelar invalida los boletos disponibles. Si ya hubo un acceso, se rechaza la cancelación básica.

## Conciliación y liquidación

El proveedor concilia los movimientos por agencia y moneda. El corte reserva cada movimiento para una sola liquidación. Propietarios y administradores del proveedor aprueban y registran el pago simulado. La agencia consulta sus liquidaciones y movimientos. Se conserva la referencia simulada y la auditoría para ambas partes.

Si un reembolso revierte un movimiento de un corte aún impago, se debe volver a conciliar y aprobar antes del pago. Una reversión posterior al pago queda para un nuevo corte. Mientras el saldo sea cero o negativo, se arrastra y el simulador rechaza el pago. No se modifican liquidaciones pagadas. El saldo de comisiones pendiente excluye los movimientos liquidados y pagados.

## Reportes de operación

El resumen usa el rango semiabierto `[inicio, fin)`. Cuenta transiciones de reserva y accesos dentro del período. Presenta cobros, reembolsos, comisiones y liquidaciones por moneda, sin conversiones. La agencia ve sus ventas atribuidas y sus movimientos autorizados. El reporte agrega datos y evita exponer fichas de clientes.

## Webhook simulado

El panel genera un evento firmado de prueba `voucher.issued`. Si el voucher aún no existe, la recepción devuelve pendiente y el mismo evento puede reintentarse. Cuando se procesa, los reintentos posteriores devuelven el estado registrado y no repiten el procesamiento. Se auditan los intentos válidos y se rechaza reutilizar el identificador con otro contenido.

Para el emisor local, la firma HMAC-SHA-256 cubre el timestamp en segundos, un punto y el JSON original, con una tolerancia de cinco minutos. La clave del emisor se deriva por organización a partir de la clave local de la aplicación. El simulador permanece local. La selección y activación de proveedores externos siguen pendientes.

## Demostración

1. Confirma una reserva con inicio dentro de los próximos 30 minutos. Abre el voucher, consulta un QR y escanéalo o pega su código. Intenta usarlo de nuevo y comprueba la denegación.
2. Confirma una reserva atribuida a una agencia con un abono. Concilia, aprueba y paga la comisión simulada. Desde la agencia, consulta el corte pagado.
3. Cancela esa reserva antes del servicio, si sus boletos siguen disponibles. Confirma que la liquidación pagada conserva su total y la reversión aparece en el saldo siguiente.
4. Genera un reporte del período y envía dos veces el mismo webhook del voucher confirmado de la primera reserva. El segundo intento debe aparecer como repetido.

[Contrato OpenAPI](openapi/v1.yaml)
