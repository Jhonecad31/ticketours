# Ticketours

Plataforma SaaS híbrida multempresa para reservaciones genéricas. La organización proveedora controla el inventario que ofrece a distribuidores autorizados.

## Documentación

- [Documento Maestro](docs/Ticketours_Documento_Maestro_Codex_v1.0.md)
- [Alcance y aceptación del MVP](docs/MVP.md)
- [Decisiones de arquitectura](docs/adr/README.md)
- [Modelo de datos](docs/data-model.md)
- [Estados de reserva](docs/reservation-states.md)
- [OpenAPI v1](docs/openapi/v1.yaml)
- [Decisiones abiertas](docs/OPEN_QUESTIONS.md)

## Arranque local

Requiere Docker con Compose v2 o una instancia local de PostgreSQL 17, PHP 8.2 con `pdo_pgsql`, Composer 2 y Node.js 24 con npm. Ejecuta desde la raíz:

```powershell
docker compose -f infra/compose.yaml config --quiet
docker compose -f infra/compose.yaml up -d --wait
docker compose -f infra/compose.yaml ps
```

Compose publica PostgreSQL en `127.0.0.1:5432` y Redis en `127.0.0.1:6379`. PostgreSQL usa la base `ticketours`, el usuario `ticketours` y la contraseña local `ticketours_local`. Si cambias `TICKETOURS_POSTGRES_PORT`, actualiza `DB_PORT` en el archivo local de Laravel. Para detener los servicios conservando los datos, usa `docker compose -f infra/compose.yaml down`.

En una terminal, desde la raíz:

```powershell
cd apps/api
composer install
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
php artisan key:generate
php artisan migrate
php artisan ticketours:platform-user operador@example.test --name="Operador de pruebas"
php artisan serve --host=127.0.0.1 --port=8000
```

Genera la clave únicamente al configurar el archivo local. La provisión de usuario solicita la contraseña de forma interactiva. En otra terminal:

```powershell
cd apps/web
npm.cmd ci
npm.cmd run dev -- --hostname 127.0.0.1
```

Abre `http://127.0.0.1:3000`. Next.js reenvía `/api/v1/*` a Laravel. Para otro puerto del backend, ajusta `TICKETOURS_API_ORIGIN` antes de iniciar Next.js. Usa `SESSION_SECURE_COOKIE=true` en entornos con HTTPS.

## Demostración de Fase 1

1. Inicia sesión con la cuenta de plataforma y crea dos organizaciones.
2. Desde la primera organización, genera una invitación para un administrador. Usa el código con un correo de prueba nuevo para crear la cuenta invitada.
3. Invita ese mismo correo como agente de reservaciones en la segunda organización. Inicia sesión como el invitado para aceptar el código.
4. Con esa cuenta, consulta las membresías y auditoría de la primera organización y verifica la denegación de esos permisos en la segunda. Con la cuenta propietaria, verifica los eventos de alta, invitación y aceptación en cada organización.

Los códigos se comparten manualmente y vencen a las 48 horas. Para probar aislamiento, permisos y auditoría de forma automatizada, ejecuta `php vendor/bin/phpunit` en `apps/api/`. Por defecto usa SQLite en memoria. Exporta las variables `DB_*` de una base PostgreSQL **dedicada a pruebas** para ejecutar la misma suite en PostgreSQL. En `apps/web/`, ejecuta `npm.cmd run lint` y `npm.cmd run build`.
