# Estados de reserva

La Fase 3 habilita la creación en retenida, la confirmación manual por propietario o administrador y la cancelación directa con motivo antes del servicio. El vencimiento registra una transición del sistema y libera inventario. Los flujos planeados de revisión, modificación y aprobación se muestran para fases posteriores. Cada cambio persiste el historial con actor, instante, motivo y correlation ID. D05 define quién puede confirmar, cuándo se exige revisión y qué cancelaciones requieren aprobación.

| Desde | Hacia | Actor o permiso candidato | Efecto |
|---|---|---|---|
| borrador | retenida | agente autorizado para crear reservas | Vincula una retención vigente cotizada |
| retenida | pendiente_confirmación | agente autorizado para solicitar confirmación | Pasa a revisión con vigencia según política |
| retenida o pendiente_confirmación | confirmada | actor con permiso de confirmación o proceso autorizado | Compromete inventario y fija precio y política atómicamente |
| retenida o pendiente_confirmación | vencida | proceso interno o comprobación transaccional | Libera asignaciones vigentes una sola vez |
| pendiente_confirmación | rechazada | actor autorizado para decidir | Registra motivo y libera inventario si corresponde |
| retenida o confirmada | cancelada | actor autorizado para cancelar | Evalúa política, registra motivo y restituye inventario si aplica |
| confirmada | cancelación_pendiente | actor autorizado para solicitar cancelación | Conserva compromiso hasta la decisión de cancelación |
| cancelación_pendiente | cancelada o confirmada | actor autorizado para decidir | Aplica cancelación o registra rechazo de la solicitud |
| confirmada | modificación_pendiente | actor autorizado para solicitar modificación | Conserva inventario anterior durante cotización y validación |
| modificación_pendiente | confirmada | actor autorizado para decidir | Si se aprueba, asegura el nuevo inventario antes de liberar el anterior |

La API de creación directa registra la reserva en `retenida` con una retención existente. Las revisiones, modificaciones y aprobaciones se habilitan según entregable y política aprobada. La cancelación de una reserva con movimientos financieros registra los ajustes cuando el flujo de pagos esté disponible.

El estado de pago se deriva de sus movimientos: pendiente, parcial, pagado, reembolso_pendiente, reembolso_parcial, reembolsado o fallido. Operación registra pendiente, checkin_parcial, checkin_completo, en_servicio, completada o no_presentado. Producto y convenio tendrán sus propias matrices antes de implementar sus transiciones.
