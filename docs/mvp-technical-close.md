# Cierre técnico del MVP · 19 de noviembre de 2026

Se validó la versión local de Ticketours con PHP 8.2, PostgreSQL 17.11, Node.js 24 y Chrome. Las pruebas usaron organizaciones, clientes, pagos y webhooks simulados en un clúster temporal.

## Aceptación A1–A10

| Criterio | Evidencia |
|---|---|
| A1 · Aislamiento | Los accesos a catálogo, reservas y datos de otra organización se rechazaron. Una agencia y un proveedor vieron sus ventas autorizadas. Un tercero quedó fuera. |
| A2 · Permisos por organización | La misma cuenta tuvo permisos distintos en dos organizaciones. El permiso delegado de check-in exigió una membresía activa. |
| A3 · Último cupo | Una venta directa y una venta por agencia enviaron retenciones simultáneas a dos procesos Laravel. Ambas esperaron un bloqueo PostgreSQL. Una recibió `201` y confirmó. La otra recibió `409 INVENTORY_UNAVAILABLE`. |
| A4 · Expiración | Laravel encoló y ejecutó dos veces el trabajo para una reserva retenida con un abono simulado. Hubo un solo vencimiento y un solo reembolso. Las colas de pendientes y fallidos quedaron vacías. La suite comprobó la recuperación de cupo. |
| A5 · Precio y política | Cambiar tarifa y política después de cotizar conservó el importe y las versiones históricas al confirmar. |
| A6 · Pago parcial | El 50 % permitió confirmar con la política habilitada. La suite rechazó un importe inferior al umbral y un pago parcial bajo la política por defecto. |
| A7 · Cancelación | La cancelación antes del servicio registró historial, restituyó el cupo y reembolsó el abono. Una cancelación tras una liquidación pagada generó la reversión del siguiente corte. |
| A8 · Idempotencia | Las repeticiones con la misma clave devolvieron la misma reserva. La suite comprobó el conflicto si la misma clave llevaba otro contenido. |
| A9 · Auditoría | La suite verificó eventos de cambios sensibles con correlación, incluidos alta, invitaciones, publicación, reservas, vencimiento, abonos, devoluciones y comisiones. |
| A10 · Reserva en el navegador | Chrome completó publicación, cotización, retención, registro del cliente, pago al 50 %, confirmación y voucher con QR. El flujo usó adaptadores locales. |

La suite de Laravel pasó con **16 pruebas y 444 aserciones**. Pasaron Pint, la instalación desde los lockfiles, lint y la compilación de Next.js. OpenAPI 0.7.0 se validó y coincidió con las **67 rutas activas**.

## Programador y cola

El ensayo inicial detectó que Laravel intentaba leer `queue` y `connection` de `ExpireHolds` sin que el trabajo tuviera las capacidades de encolado. Se añadió `Queueable` y una prueba que comprueba que `schedule:run` despacha exactamente un trabajo de vencimiento.

Para repetir la comprobación, configura una **base PostgreSQL temporal dedicada** en `apps/api` y usa la cola de base de datos. Crea una reserva retenida con un abono simulado y deja vencer su retención.

```powershell
$env:QUEUE_CONNECTION = 'database'
php artisan schedule:list
php artisan schedule:run
php artisan queue:work database --once --tries=1 -v
php artisan schedule:run
php artisan queue:work database --once --tries=1 -v
```

Comprueba el estado de reserva y pedido, el reembolso y el único evento `hold.expired` de la retención. Revisa `jobs` y `failed_jobs` antes y después de cada ejecución.

## Respaldo y restauración

Se apagó PostgreSQL limpiamente, se archivó el clúster completo y se extrajo el archivo en otro directorio. El clúster restaurado arrancó en un puerto distinto. Coincidieron los conteos y el SHA-256 del contenido ordenado de **11 tablas** de reservas, ítems, pedidos, pagos, reembolsos, liquidaciones, comisiones, auditoría, boletos, check-in y webhooks. Desde la API restaurada, el voucher devolvió el mismo QR con su uso registrado. El segundo intento de usar ese boleto devolvió `409 TICKET_ALREADY_USED`. La copia de prueba contenía 4 reservas, 4 pagos, 3 reembolsos, 1 liquidación, 78 eventos de auditoría y 1 acceso registrado. El archivo físico ocupó 53 920 768 bytes.

Para repetir el respaldo físico, prepara un clúster de pruebas aislado, sin tablespaces ni WAL externos, y usa los mismos binarios de PostgreSQL para ambos arranques. Conserva la clave `APP_KEY` de la aplicación por separado y vuelve a suministrarla al arrancar Laravel contra la copia. Los secretos cifrados de los boletos requieren esa clave. [Respaldo físico de PostgreSQL 17](https://www.postgresql.org/docs/17/backup-file.html)

```powershell
# $pgCtl apunta al pg_ctl.exe de PostgreSQL 17.
# $source es el directorio de datos del clúster de prueba.
# $target es un directorio vacío y $archive queda fuera de $source.
& $pgCtl -D $source -m fast -w stop
tar.exe -cf $archive -C (Split-Path -Parent $source) (Split-Path -Leaf $source)
Get-FileHash -LiteralPath $archive -Algorithm SHA256
tar.exe -xf $archive -C $target
$restored = Join-Path $target (Split-Path -Leaf $source)
& $pgCtl -D $restored -l (Join-Path $target 'restore.log') -o '-h 127.0.0.1 -p 55439' -w start
```

Apunta Laravel al puerto restaurado. Compara conteos y contenido antes de generar operaciones nuevas. Consulta un voucher emitido antes del respaldo y confirma que un boleto ya utilizado conserva su registro de acceso. Detén el clúster restaurado al terminar la prueba con `pg_ctl -D $restored -m fast -w stop`.

## CI y repetición local

El [workflow de CI](../.github/workflows/ci.yml) tiene dos trabajos. API instala con Composer, valida Pint y ejecuta PHPUnit con PostgreSQL. Web instala con `npm ci`, ejecuta lint y compila. Se revisó el YAML y se ejecutaron los comandos equivalentes en Windows con PostgreSQL portátil. Quedó pendiente comprobar una ejecución remota en GitHub Actions y el arranque mediante Docker Compose en este entorno.

En `apps/api`, apunta las variables `DB_*` a una **base de pruebas vacía y dedicada** para reproducir PHPUnit sobre PostgreSQL. En Windows, habilita `pdo_pgsql` para el ejecutable PHP que use Laravel. Luego ejecuta los comandos de verificación.

```powershell
composer install --no-interaction --prefer-dist
php vendor/bin/pint --test
php vendor/bin/phpunit
```

Ejecuta también las verificaciones en `apps/web`.

```powershell
npm.cmd ci
npm.cmd run lint
npm.cmd run build
```

Para reproducir la prueba de concurrencia, inicia dos procesos Laravel conectados a la misma base temporal. Genera una cotización directa y otra de agencia antes de retener la última unidad. Envía los dos `POST /holds` en simultáneo por procesos distintos y con claves de idempotencia distintas. Para sincronizar ambas solicitudes, mantén bloqueada la fila de la sesión desde otra transacción hasta observar dos esperas en `pg_stat_activity` y libera el bloqueo. Verifica `201` y `409`, confirma la reserva ganadora y comprueba el cupo.

Para el recorrido de navegador, usa las [guías de venta directa](sales-phase-four.md), [venta por agencia](distribution-phase-five.md) y [operación](operations-phase-six.md). Las [decisiones abiertas](OPEN_QUESTIONS.md) conservan los pendientes de proveedores reales, fiscalidad y check-in sin conexión.
