# Catálogo, tarifas e inventario · Fase 2

Propietarios y administradores configuran y publican productos. Los agentes de reservaciones consultan catálogo, tarifas y disponibilidad en organizaciones donde tienen membresía activa. Cada operación aplica el ámbito de la organización y cada cambio de configuración queda auditado.

## Publicación

| Estado | Transiciones |
|---|---|
| Borrador | Revisión, publicado, archivado |
| Revisión | Borrador, publicado, archivado |
| Publicado | Suspendido |
| Suspendido | Revisión, publicado, archivado |
| Archivado | Terminal |

Publicar requiere una política versionada, al menos una variante y, para cada variante, una tarifa base y configuración de inventario con vigencia. Para sesiones cuenta una sesión futura con cupo positivo. Para recursos cuenta una ventana que aún no termina. Los bloqueos pueden dejar temporalmente un recurso sin disponibilidad. Para añadir variantes después de publicar, se suspende primero el producto.

Cada cambio de tarifa o política crea una versión inmutable. La versión de número más alto es la vigente. El importe se guarda como cadena decimal exacta con hasta 18 dígitos enteros y 12 decimales, conservando ceros finales. La moneda se escribe con tres letras mayúsculas. D09 conserva las decisiones de monedas admitidas, escala comercial y conversiones.

## Consulta de disponibilidad

El rango recibe fechas RFC 3339 con segundos y desplazamiento explícito. Una consulta cubre hasta 93 días. Se guarda UTC y se devuelve también la zona IANA de la organización.

- **Sesión.** Devuelve sesiones futuras completas contenidas en el intervalo y su cupo configurado.
- **Recurso exclusivo.** Une las ventanas habilitadas para la variante, las recorta al rango futuro solicitado y resta los bloqueos del recurso. Los intervalos son semiabiertos: el fin de una ventana puede coincidir con el inicio de otra.
- **Publicación.** La consulta requiere una variante de producto publicado. Los horarios se administran en el catálogo interno por organización.

La respuesta es una observación del inventario. La Fase 3 introducirá cotizaciones, retenciones y compromiso de inventario. Los términos de política de esta fase son texto versionado. Las reglas automáticas de confirmación y cancelación dependen de D05.

## Demo

1. Abre el catálogo de una organización con una cuenta de propietario o administrador. Crea un producto y dos variantes de modalidades distintas.
2. Guarda una versión de política y una tarifa para cada variante.
3. Programa una sesión futura con cupo. Crea un recurso y una ventana para la otra variante. Agrega un bloqueo parcial a la ventana.
4. Publica. Consulta cada variante y verifica el cupo de la sesión y los tramos libres del recurso. Suspende y comprueba que la disponibilidad deja de estar visible.

[Contrato OpenAPI](openapi/v1.yaml)
