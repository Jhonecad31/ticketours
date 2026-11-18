<?php

use App\Http\Middleware\ApiRequest;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ApiRequest::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (QueryException $error) {
            Log::error('Database operation failed.', ['sql_state' => $error->getCode()]);

            return false;
        });
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->is('api/v1', 'api/v1/*')) {
                return null;
            }

            $status = match (true) {
                $error instanceof AuthenticationException => 401,
                $error instanceof ValidationException => 422,
                $error instanceof HttpExceptionInterface => $error->getStatusCode(),
                default => 500,
            };
            [$code, $message] = match ($status) {
                400 => ['INVALID_REQUEST', 'Solicitud inválida.'],
                401 => ['UNAUTHENTICATED', 'Inicia sesión con las credenciales correspondientes.'],
                403 => ['FORBIDDEN', 'Tu cuenta no tiene permiso para esta operación.'],
                404 => ['NOT_FOUND', 'El recurso no está disponible.'],
                405 => ['METHOD_NOT_ALLOWED', 'Método no permitido.'],
                409 => match ($error->getMessage()) {
                    'INVITATION_ALREADY_PENDING' => ['INVITATION_ALREADY_PENDING', 'Ya hay una invitación vigente.'],
                    'PRODUCT_NOT_READY' => ['PRODUCT_NOT_READY', 'Configura una política, una tarifa y el inventario de cada variante antes de publicar.'],
                    'INVALID_PRODUCT_TRANSITION' => ['INVALID_PRODUCT_TRANSITION', 'El estado del producto impide esta operación.'],
                    'INVALID_INVENTORY_MODALITY' => ['INVALID_INVENTORY_MODALITY', 'La configuración de inventario no corresponde a la modalidad.'],
                    'INVENTORY_UNAVAILABLE' => ['INVENTORY_UNAVAILABLE', 'El cupo o intervalo solicitado ya no está disponible.'],
                    'QUOTE_EXPIRED' => ['QUOTE_EXPIRED', 'La cotización venció.'],
                    'QUOTE_ALREADY_USED' => ['QUOTE_ALREADY_USED', 'La cotización ya se usó para una retención.'],
                    'QUOTE_SELECTION_MISMATCH' => ['QUOTE_SELECTION_MISMATCH', 'La selección debe coincidir con la cotización.'],
                    'HOLD_EXPIRED' => ['HOLD_EXPIRED', 'La retención venció.'],
                    'INVALID_STATE_TRANSITION' => ['INVALID_STATE_TRANSITION', 'El estado actual impide esta operación.'],
                    'IDEMPOTENCY_KEY_REUSED' => ['IDEMPOTENCY_KEY_REUSED', 'La clave se usó con otra solicitud.'],
                    'CANCELLATION_CLOSED' => ['CANCELLATION_CLOSED', 'El servicio ya comenzó.'],
                    'MEMBER_ALREADY_EXISTS' => ['MEMBER_ALREADY_EXISTS', 'La cuenta ya existe o ya pertenece a la organización.'],
                    default => ['CONFLICT', 'La operación entra en conflicto con el estado actual.'],
                },
                419 => ['CSRF_TOKEN_MISMATCH', 'Renueva la sesión e inténtalo de nuevo.'],
                422 => ['VALIDATION_FAILED', 'Revisa los datos de la solicitud.'],
                429 => ['RATE_LIMITED', 'Intenta de nuevo más tarde.'],
                default => ['INTERNAL_ERROR', 'No se pudo completar la operación.'],
            };
            $id = $request->attributes->get('correlation_id');

            return response()->json([
                'code' => $code,
                'message' => $message,
                'details' => $error instanceof ValidationException ? (object) $error->errors() : (object) [],
                'correlation_id' => $id,
            ], $status, [
                ...($error instanceof HttpExceptionInterface ? $error->getHeaders() : []),
                'Cache-Control' => 'no-store',
                'X-Correlation-ID' => $id,
            ]);
        });
    })->create();
