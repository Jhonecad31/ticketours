# Decisiones abiertas

| ID | Decisión por cerrar | Dependencia |
|---|---|---|
| D01 | Aprobado: la organización es la unidad de aislamiento y administración. Marca, sucursal y sede quedan subordinadas. Identidad fiscal pendiente de D06. | ADR 0004 |
| D02 | ¿Qué organización controla cada dato de cliente en una venta cruzada y con qué permisos se comparte? | Distribución y privacidad |
| D03 | Aprobados sesiones revocables con CSRF, permisos Laravel, claves compuestas y alta con permiso de plataforma. Quedan la evaluación de RLS y la autorización de soporte para fases posteriores. | ADR 0004 |
| D04 | Adoptados el bloqueo por sesión y la exclusión por recurso como diseños iniciales. Definir duración, unidad de configuración, límites y política de sobreventa. | Contrato de retenciones y diseño físico |
| D05 | ¿Quién confirma y en qué condiciones? Definir configuración manual o automática, vigencia en revisión, publicación y cancelación básica. | Transiciones y activación de confirmación |
| D06 | ¿Qué países, impuestos, requisitos de comprobantes y plazos de privacidad aplican? | Precios finales, fiscalidad, retención y solicitudes de privacidad |
| D07 | ¿Quién cobra al cliente en ventas distribuidas? | Obligaciones comerciales y custodia de fondos |
| D08 | ¿Cómo se calculan, ajustan y liquidan comisiones y reembolsos? | Movimientos, conciliación y liquidación |
| D09 | ¿Qué monedas y escalas se admiten y cómo se autoriza y registra el tipo de cambio? | Restricciones monetarias y conversiones |
| D10 | ¿Qué servicios especializados se incluyen y qué requisitos tienen las modalidades posteriores? | Límites de producto y nuevas modalidades |
| D11 | ¿Qué proveedores de pagos y correo se aprobarán? ¿Qué política permite pago parcial en la prueba del MVP? | Adaptadores y aceptación de Fase 4 |
| D12 | ¿Qué flujo offline se admite y cómo se resuelven validaciones concurrentes? | Contrato y operación de check-in |

Las decisiones se cierran antes de la primera migración o implementación que dependa de ellas. Los contratos de confirmación describen precondiciones mientras se definen las reglas comerciales.
