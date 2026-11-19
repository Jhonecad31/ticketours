# Ventas y vouchers · Fase 4

Cada reserva tiene un pedido con el total, la moneda y la regla de pago de la política cotizada. El pedido y el pago tienen estados propios. El estado del pago se deriva de los abonos y reembolsos registrados.

- La política exige el pago total por defecto. Al crear una versión de política, propietario o administrador puede habilitar la confirmación desde el 50 %. La comparación es decimal exacta. Un producto gratuito se puede confirmar con saldo cero.
- Los abonos simulados se aplican íntegramente a un pedido en su moneda. Propietarios y administradores registran los abonos. La operación requiere `Idempotency-Key`. El importe debe ser positivo y quedar dentro del saldo.
- La confirmación manual conserva la vigencia de la retención como precondición. Al confirmar se compromete el inventario y se emite un voucher de prueba consultable e imprimible. Si queda saldo, aparece en el voucher.
- Cancelar antes del inicio o dejar vencer una reserva retenida devuelve todo lo abonado por el simulador. El movimiento, la liberación de inventario y el cambio de estado se registran en la misma transacción. Los vouchers de reservas canceladas dejan de estar disponibles y conservan su registro histórico.
- Las reservas anteriores reciben un pedido con su importe original. Las confirmaciones históricas conservan el estado. Para una confirmación histórica, se habilita el voucher cuando se registra el pago requerido.

## Demostración

1. Registra una política que permita pago parcial, publica el producto y crea una reserva retenida desde el catálogo.
2. Desde el pedido, abona el 50 % con el simulador y confirma la reserva.
3. Consulta e imprime el voucher. Comprueba el saldo pendiente.
4. Cancela con motivo antes del inicio y consulta el pedido. El abono aparece reembolsado y se restituye el inventario.

La fase funciona con un adaptador local y datos de prueba. Los proveedores reales y las reglas de liquidación quedan para entregables posteriores.

[OpenAPI](openapi/v1.yaml)
