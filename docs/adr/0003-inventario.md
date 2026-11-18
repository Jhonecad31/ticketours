# ADR 0003 · Inventario y consistencia

**Decisión.** PostgreSQL determina el cupo comprometido, la vigencia de retenciones y el resultado idempotente. El programador Laravel encola el trabajo de expiraciones. En local usa PostgreSQL y puede ejecutarse sobre Redis cuando se configure ese transporte. Las consultas de disponibilidad ofrecen una observación temporal. La retención y la confirmación validan inventario dentro de sus propias transacciones.

En sesiones, bloquear la fila de inventario y comprobar unidades comprometidas y retenciones vigentes. En recursos exclusivos, serializar por recurso y aplicar exclusión de intervalos activos `[inicio, fin)`. Cada operación depura vencimientos pertinentes antes de la escritura. Operaciones con varias asignaciones adquieren bloqueos en un orden estable. La confirmación valida el estado y vigencia, compromete inventario y persiste instantáneas e historial en una sola transacción.

**Idempotencia.** Guardar clave, huella de solicitud y resultado de la operación con ámbito de organización, actor y operación. Persistir el resultado exitoso con la mutación. Una repetición devuelve el resultado registrado. Una clave igual con cuerpo distinto genera conflicto. Las referencias de ruta forman parte de la huella. Expiración, liberación y cancelación aplican efectos a partir del estado persistido.

**Pendientes.** D04 y D05 quedaron aprobadas para el flujo directo de Fase 3. Consulta [reservas directas](../reservations-phase-three.md). La retención de registros históricos sigue dependiendo de la política de privacidad.
