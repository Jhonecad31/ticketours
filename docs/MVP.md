# Alcance y entrega del MVP

La reserva directa cubre sesiones con cupo y recursos exclusivos por intervalo. La organización configura catálogo, horarios, precios y políticas. El flujo consulta disponibilidad, cotiza, retiene inventario, registra cliente y participantes, confirma según política y emite un voucher de prueba.

El modelo reserva puntos de extensión para capacidad diaria, cita variable, admisión por boleto, renta por fechas, reserva privada y paquetes. El alcance comercial de las modalidades se resuelve en D10.

| Fase | Entrega y dependencia | Evidencia esperada |
|---|---|---|
| 0 | ADR, ERD, estados, OpenAPI y Compose | Coherencia entre documentos, validación de contratos y arranque local |
| 1 | Monorepo, identidad, organizaciones, membresías, permisos y auditoría | A1 acceso cruzado denegado, A2 permisos distintos para un usuario, A9 cambios sensibles auditados |
| 2 | Catálogo, variantes, sesiones, recursos, tarifas y disponibilidad. Depende del ámbito validado en Fase 1 | Publicación conforme a requisitos y consulta en ambas modalidades |
| 3 | Retenciones, clientes, reservas, confirmación de dominio y cancelación básica. Depende del inventario y precios de Fase 2 | A3 una sola confirmación sobre el último cupo, A4 vencimiento idempotente, A5 instantáneas históricas, A7 historial y restitución según política, A8 repetición sin duplicados |
| 4 | Ventas, pago simulado, confirmación integrada y voucher. Depende del cierre de P0 | A6 pago parcial cuando la política lo permite y A10 reserva completa desde el frontend con adaptadores simulados |
| 5 | Agencias y comisiones antes de red de distribución autorizada. Depende de las decisiones comerciales | Convenio limita acceso y proveedor conserva inventario |
| 6 | Check-in, conciliación, reportes e integraciones. Depende de boletos y movimientos de venta | Validación de uso único, conciliación trazable y webhooks idempotentes |

Cada fase cierra con demo, pruebas, documentación y aprobación. A1–A10 son la puerta de salida del MVP. A1, A2 y A9 se vuelven a verificar al incorporar recursos de negocio.

Consulta la [revalidación A1–A10 y la evidencia de restauración](mvp-technical-close.md).
