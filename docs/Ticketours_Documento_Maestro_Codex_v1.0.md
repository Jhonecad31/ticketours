# TICKETOURS — Documento Maestro para Codex v1.0



## 0. Control del documento

Fecha: 18 noviembre 2026. Estado: especificación inicial para iniciar desarrollo incremental. Modelo aprobado: C, SaaS híbrido multempresa con distribución autorizada. Alcance aprobado: plataforma genérica de reservas. Referencia funcional: Wellet.Pro; no copiar código, marca, textos ni interfaces propietarias. Las decisiones técnicas son propuestas sujetas a validación.

## 1. Misión y principios

Construir una plataforma propia donde cada organización administra catálogo, inventario, reservas, clientes, pagos y operaciones de forma aislada, y opcionalmente comparte productos con distribuidores autorizados. Fuente de verdad del inventario: organización proveedora. Seguridad, trazabilidad y consistencia prevalecen sobre velocidad de entrega.

## 2. Stack inicial propuesto

Frontend: Next.js App Router, TypeScript, React, Tailwind CSS y HeroUI. Backend: Laravel API REST, PHP, PostgreSQL. Redis para caché, colas y retenciones; almacenamiento S3 compatible para documentos; Docker Compose para entorno local. Integraciones de pagos y correo mediante adaptadores; comenzar con proveedores simulados. No conectar credenciales reales sin aprobación. Arquitectura: monolito modular en backend, frontend separado, API versionada /api/v1.

## 3. Estructura sugerida del repositorio

apps/web/ (Next.js), apps/api/ (Laravel), docs/ (decisiones, contratos y diagramas), infra/ (Docker y despliegue), tests/ (pruebas end-to-end si procede). En apps/api separar módulos: Identity, Organizations, Catalog, Inventory, Pricing, Reservations, Customers, Sales, Payments, Distribution, Commissions, Tickets, Operations, Notifications, Reporting, Audit, Integrations. No crear microservicios al inicio.

## 4. Actores y autorización

Superadministrador de plataforma; administrador de plataforma; soporte con acceso auditado; auditor; propietario de organización; administrador; gerente comercial; agente de reservaciones; operaciones; finanzas; vendedor; agente de agencia; validador; cliente. Usar RBAC con permisos granulares por organización, sede y recurso. Un usuario puede pertenecer a varias organizaciones; el contexto de tenant debe validarse en servidor, nunca confiar en un tenant_id enviado por el cliente.

## 5. Módulos funcionales y prioridad

P0: SaaS y organizaciones, identidad/permisos, catálogo, inventario, tarifas, reservaciones, clientes, auditoría. P1: ventas, pagos, agencias, comisiones, boletos QR, operaciones/check-in, notificaciones y reportes. P2: red de distribución, integraciones, white-label. P3: automatizaciones. Mantener contratos y límites entre módulos desde P0; no implementar P1–P3 sin completar los criterios de aceptación de P0.

## 6. Modalidades de reserva

El núcleo debe admitir: (a) sesión con fecha/hora y cupo, (b) capacidad por día, (c) recurso exclusivo por intervalo, (d) cita de duración variable, (e) admisión por boleto, (f) renta por rango de fechas, (g) reserva privada, (h) paquete. Para MVP implementar primero sesiones con cupo y reservas de recursos por intervalo; diseñar interfaces extensibles para las demás modalidades sin afirmar que ya funcionan.

## 7. Entidades conceptuales

Organización, marca, sucursal, sede, usuario, membresía, rol, permiso, producto, variante, categoría, tarifa, regla de precio, calendario, sesión, recurso, capacidad, bloqueo, retención, cliente, participante, pedido, reserva, ítem de reserva, historial de estado, pago, aplicación de pago, reembolso, boleto, validación, convenio, publicación comercial, comisión, liquidación, evento de auditoría, integración y webhook. Es una guía conceptual: antes de migraciones documentar ERD, claves, índices, restricciones y políticas de borrado.

## 8. Reglas de negocio obligatorias

R01: no sobreventa sin política explícita. R02: retenciones de cupo con expiración configurable y liberación idempotente. R03: confirmar reserva y comprometer inventario en una transacción consistente. R04: estado de reserva, pago y operación separados. R05: conservar precio y política versionados al confirmar. R06: moneda de cada transacción inmutable; conversiones explícitas. R07: proveedor conserva control del inventario compartido. R08: ninguna empresa ve datos privados de otra sin autorización. R09: webhooks y reintentos idempotentes. R10: cancelaciones generan eventos, ajustes de inventario y movimientos financieros trazables. R11: QR único, verificable y protegido contra doble uso. R12: permisos validados en cada operación sensible. R13: auditar cambios críticos. R14: evitar borrado físico de transacciones financieras.

## 9. Estados independientes

Reserva: borrador, retenida, pendiente_confirmación, confirmada, modificación_pendiente, cancelación_pendiente, cancelada, vencida, rechazada. Pago (estado derivado): pendiente, parcial, pagado, reembolso_pendiente, reembolso_parcial, reembolsado, fallido. Operación: pendiente, checkin_parcial, checkin_completo, en_servicio, completada, no_presentado. Producto: borrador, revisión, publicado, suspendido, archivado. Convenio: propuesto, pendiente_aceptación, activo, suspendido, vencido, terminado. Documentar transiciones, roles y efectos antes de implementarlas.

## 10. Flujos principales

F01 Alta de empresa: registro > organización > configuración > invitaciones. F02 Publicar producto: modalidad > calendario/recurso > tarifa > políticas > validación > publicación. F03 Reserva directa: consultar cupo > cotizar > retener > datos > pago/confirmación > voucher. F04 Venta por agencia: convenio > tarifa autorizada > cupo del proveedor > reserva > obligaciones. F05 Venta por vendedor: atribución > reserva > pago > comisión. F06 Check-in: escaneo QR > validar derecho y uso > registrar acceso. F07 Modificar: verificar política > cotizar diferencia > asegurar nuevo cupo > aplicar cambio > liberar cupo anterior. F08 Cancelar: política > aprobación > liberar cupo > reembolso/ajuste. F09 Compartir producto: publicar > aceptar convenio > distribuir. F10 Liquidar: agrupar movimientos > conciliar > aprobar > pagar.

## 11. Contratos API sugeridos

API versionada /api/v1. Recursos iniciales: /auth, /organizations, /memberships, /products, /product-variants, /sessions, /resources, /availability, /quotes, /holds, /reservations, /customers, /audit-events. Posteriores: /orders, /payments, /refunds, /tickets, /check-ins, /agreements, /distributions, /commissions, /settlements, /webhooks. Para operaciones mutantes sensibles aceptar Idempotency-Key. Respuestas de error con código estable, mensaje, detalles y correlation_id. Paginación, filtros, zona horaria y control de permisos documentados con OpenAPI.

## 12. Seguridad y privacidad

Aislamiento por tenant en consultas, políticas y pruebas automatizadas. Contraseñas seguras, rate limiting, MFA futura, sesiones revocables, secretos fuera del repositorio, cifrado TLS, logs sin datos sensibles. Para pagos usar proveedor PCI; no almacenar datos completos de tarjetas. Accesos de soporte con autorización y auditoría. Preparar política de retención y solicitudes de privacidad por jurisdicción.

## 13. Requisitos no funcionales

Consistencia de cupos bajo concurrencia, transacciones atómicas, observabilidad (logs estructurados y correlation IDs), métricas, backups y recuperación, accesibilidad básica, interfaz responsiva, internacionalización es/en, fechas con timezone IANA y almacenamiento UTC, monedas con precisión decimal, pruebas automatizadas, CI con lint/test/build, migraciones reversibles cuando sea seguro.

## 14. Roadmap de implementación por entregables

Fase 0: ADRs, definición de MVP, ERD inicial, OpenAPI y entorno local reproducible. Fase 1: monorepo, autenticación, organizaciones, membresías, roles y auditoría. Fase 2: catálogo, variantes, sesiones, recursos, precios y disponibilidad. Fase 3: holds concurrentes, reservas, clientes, estados y cancelación básica. Fase 4: ventas, pagos simulados, confirmaciones y vouchers. Fase 5: agencias, distribución autorizada y comisiones. Fase 6: check-in, conciliación, reportes e integraciones. Cada fase termina con demo, pruebas, documentación y aprobación antes de continuar.

## 15. Criterios de aceptación MVP

A1 Dos empresas no acceden a datos ajenos. A2 Usuario con dos organizaciones tiene permisos distintos. A3 Dos solicitudes simultáneas al último cupo producen una sola confirmación. A4 Hold expirado libera cupo sin duplicaciones. A5 Reserva conserva tarifa y política históricas. A6 Una reserva puede confirmarse con pago parcial si la política lo permite. A7 Cancelar registra historia y restituye cupo cuando aplica. A8 Repetir petición con misma Idempotency-Key no duplica reserva. A9 Todos los cambios sensibles quedan auditados. A10 Frontend puede completar una reserva de prueba sin servicios externos reales.

## 16. Instrucciones operativas para Codex

Antes de escribir código: leer este documento; inspeccionar el repositorio; presentar plan corto de la fase solicitada, supuestos y archivos a tocar. No implementar todo el sistema en una sola iteración. Crear cambios pequeños y verificables, con pruebas y documentación. No inventar requisitos faltantes: registrarlos en docs/OPEN_QUESTIONS.md y pedir aprobación si bloquean diseño de datos, seguridad o finanzas. No cambiar el modelo híbrido ni el alcance genérico. No copiar Wellet.Pro. No ejecutar despliegues, borrar datos, introducir pagos reales ni añadir credenciales sin autorización. Al finalizar cada tarea reportar archivos, comandos de validación, pruebas, riesgos y siguiente paso.

## 17. Primer encargo recomendado a Codex

Implementa exclusivamente la Fase 0: crea estructura base del repositorio (si está vacío), documentación de arquitectura y decisiones (ADRs), propuesta de ERD y contrato OpenAPI mínimo para organizaciones, productos, disponibilidad y reservas. Prepara Docker Compose local y README de arranque, sin desarrollar todavía pagos ni módulos comerciales. Primero muestra el plan y confirma supuestos críticos antes de modificar archivos. Si ya existe código, preserva la estructura y propone cambios compatibles.

## 18. Decisiones pendientes para aprobación

D01 identidad legal de empresa vs marca/sucursal. D02 propiedad de datos de clientes en ventas cruzadas. D03 estrategia concreta de aislamiento de tenant. D04 bloqueo de cupos y duración. D05 confirmación manual/automática. D06 reglas fiscales y países objetivo. D07 quién cobra al cliente en ventas distribuidas. D08 comisiones, liquidaciones y reembolsos. D09 monedas y tipo de cambio. D10 alcance de servicios especializados. D11 proveedor de pagos y correo. D12 operación offline del check-in. Codex debe tratar estas decisiones como abiertas y evitar implementaciones irreversibles.
