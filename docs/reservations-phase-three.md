# Reservas directas · Fase 3

## Reglas aprobadas

- **D04.** La cotización y la retención tienen temporizadores independientes. Por defecto, cada plazo es de 15 minutos y se configura por organización. El plazo nunca sobrepasa el inicio del servicio. El ajuste acepta de 1 a 1 440 minutos y aplica a registros nuevos. Se impide la sobreventa.
- **D05.** Un agente de reservaciones puede registrar clientes, cotizar, retener y crear una reserva. Propietarios y administradores pueden confirmarla manualmente mientras la retención siga activa. También pueden cancelar una reserva retenida o confirmada antes del inicio, con motivo y restitución atómica del inventario.
- **Precio.** En sesiones se multiplica la tarifa por la cantidad de unidades con decimales exactos. Para el recurso exclusivo se cobra la tarifa fija por el intervalo. Cada cotización conserva importe, moneda y versiones. La reserva guarda una instantánea de selección, precio y términos que se conserva al confirmar.
- **Idempotencia.** Las mutaciones de retenciones y reservas requieren `Idempotency-Key`. Un reintento con la misma clave y contenido efectivo devuelve el resultado original. La misma clave con otro contenido produce `409`. Cada cotización puede originar una retención y cada retención una reserva. Los resultados exitosos se guardan junto con la mutación.
- **Inventario.** PostgreSQL bloquea cada sesión o recurso al cambiar su ocupación. Una exclusión PostgreSQL sobre intervalos activos protege los recursos incluso entre variantes. La disponibilidad descuenta las retenciones vigentes y las reservas confirmadas. Crear un bloqueo administrativo rechaza intervalos ya ocupados.

## Vencimiento y operación local

PostgreSQL es la fuente de vigencia. Las retenciones vencidas dejan de consumir inventario según su fecha, incluso si la cola está retrasada. El trabajo de vencimiento registra los estados y eventos de forma idempotente. La migración usa la extensión PostgreSQL `btree_gist`. El rol de migraciones debe poder habilitarla o la extensión debe estar instalada.

En el directorio `apps/api`, arranca el programador y el trabajador en terminales separadas, además de Laravel y Next.js:

```powershell
php artisan schedule:work
php artisan queue:work
```

El trabajo vence retenciones cada minuto. El perfil local usa la cola de base de datos. Puedes usar la cola Redis con `QUEUE_CONNECTION=redis` y un cliente PHP para Redis disponible. No hay conexión con proveedores de pago en esta fase.

## Demostración

1. Publica un producto con tarifa, política e inventario. En el panel de catálogo, elige una variante publicada y consulta un intervalo futuro.
2. Cotiza una sesión o un intervalo libre. Retén el inventario, elige o crea un cliente y registra la reserva. Guarda la referencia.
3. Como propietario o administrador, registra el [pago simulado requerido](sales-phase-four.md), confirma y comprueba que la disponibilidad sigue descontando el inventario comprometido.
4. Antes del inicio, cancela con motivo y comprueba que se restituye el cupo o intervalo. Consulta el estado por referencia y verifica la auditoría.

[OpenAPI](openapi/v1.yaml) · [Estados de reserva](reservation-states.md)
