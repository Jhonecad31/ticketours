# ADR 0003 · Inventario y consistencia

**Decisión.** PostgreSQL determina el cupo comprometido, la vigencia de retenciones y el resultado idempotente. Redis programa expiraciones y permite reintentos. Las consultas de disponibilidad ofrecen una observación temporal. La retención y la confirmación validan inventario dentro de sus propias transacciones.

En sesiones, bloquear la fila de inventario y comprobar unidades comprometidas y retenciones vigentes. En recursos exclusivos, serializar por recurso y aplicar exclusión de intervalos activos `[inicio, fin)`. Cada operación depura vencimientos pertinentes antes de la escritura. Operaciones con varias asignaciones adquieren bloqueos en un orden estable. La confirmación valida el estado y vigencia, compromete inventario y persiste instantáneas e historial en una sola transacción.

**Idempotencia.** Guardar clave, huella de solicitud y resultado de la operación con ámbito de organización, actor y operación. Persistir el resultado exitoso con la mutación. Una repetición devuelve el resultado registrado. Una clave igual con cuerpo distinto genera conflicto. Las referencias de ruta forman parte de la huella. Expiración, liberación y cancelación aplican efectos a partir del estado persistido.

**Pendientes.** D04 define duración, configuración, conservación de las claves y política de sobreventa. D05 define cómo se confirma y cuándo procede la cancelación.
