# ADR 0001 · Monolito modular y repositorio

**Decisión.** Next.js App Router con TypeScript, React, Tailwind CSS y HeroUI en `apps/web/`. Laravel con REST versionada en `apps/api/`. PostgreSQL guarda los datos transaccionales y Redis soporta caché y colas. Los dominios dependen de contratos explícitos de otros módulos y Laravel aplica las reglas de negocio.

**Estructura.** `docs/` contiene decisiones, ERD y OpenAPI. `infra/` contiene Compose. `tests/` albergará pruebas integradas cuando exista un flujo ejecutable. Las carpetas de aplicaciones se crean al iniciar su implementación.

**Dependencias.** Identity, Organizations y Audit preceden a Catalog, Inventory y Pricing. Customers y Reservations consumen sus contratos. Sales y Payments se incorporan tras el cierre de P0. Distribution, Commissions, Tickets, Operations, Notifications, Reporting e Integrations se incorporan con sus entregables. Pago y correo tendrán adaptadores simulados al inicio. El almacenamiento compatible con S3 se introduce cuando un flujo documental lo requiera.

**Operación.** CI con lint, pruebas y compilación al incorporar las aplicaciones. Logs estructurados con correlation ID, métricas, copias y recuperación verificable. Fechas en UTC y zona IANA para reglas locales. Interfaz responsiva, accesible y en español e inglés. Secretos fuera del repositorio, TLS fuera del entorno local y datos de tarjeta delegados al proveedor aprobado.

**Versiones.** Compose fija las etiquetas de PostgreSQL y Redis. Las versiones de ejecución de Node.js y PHP se fijarán junto con las dependencias y archivos de bloqueo de las aplicaciones. Referencias de compatibilidad: [Next.js](https://nextjs.org/docs/app/getting-started/installation), [Laravel](https://laravel.com/docs/13.x/releases), [PostgreSQL](https://hub.docker.com/_/postgres), [Redis](https://hub.docker.com/_/redis).
