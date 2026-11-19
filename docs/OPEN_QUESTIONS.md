# Decisiones abiertas

| ID | Decisión por cerrar | Dependencia |
|---|---|---|
| D01 | Aprobado: la organización es la unidad de aislamiento y administración. Marca, sucursal y sede quedan subordinadas. Identidad fiscal pendiente de D06. | ADR 0004 |
| D02 | La agencia conserva su ficha. El proveedor recibe solo los datos necesarios para prestar el servicio asociado al convenio. | Privacidad de distribución |
| D03 | Aprobados sesiones revocables con CSRF, permisos Laravel, claves compuestas y alta con permiso de plataforma. Quedan la evaluación de RLS y la autorización de soporte para fases posteriores. | ADR 0004 |
| D04 | Fase 3 aprobada. Cotización y retención con temporizadores independientes de 15 minutos por defecto, configurables por organización y limitados por el inicio del servicio. Sobreventa deshabilitada. | Definir retención de registros históricos |
| D05 | Publicación de Fase 2 aprobada. Fase 3 aprobada con creación retenida por agente autorizado y confirmación manual o cancelación por propietario o administrador, con motivo y antes del servicio para cancelar. | Revisión, modificación y pagos en fases posteriores |
| D06 | ¿Qué países, impuestos, requisitos de comprobantes y plazos de privacidad aplican? | Precios finales, fiscalidad, retención y solicitudes de privacidad |
| D07 | El proveedor cobra y reembolsa en la moneda del pedido. Fase 5 utiliza el simulador y la agencia consulta los importes y estados de sus ventas. | Cobro real e integraciones |
| D08 | La liquidación simulada registra conciliación, aprobación y pago por agencia y moneda. Las reversiones posteriores al pago pasan al siguiente corte. Los saldos no positivos se arrastran. | Liquidaciones reales |
| D09 | Fase 2 usa importes decimales exactos y código de moneda de tres letras. Pendientes las monedas admitidas, escalas comerciales y conversiones. | Flujos monetarios |
| D10 | ¿Qué servicios especializados se incluyen y qué requisitos tienen las modalidades posteriores? | Límites de producto y nuevas modalidades |
| D11 | Fases 4 a 6 usan pagos y webhooks simulados. Pendiente elegir proveedores de pagos y correo y aprobar cualquier activación externa. | Adaptadores reales |
| D12 | Check-in en línea de uso único, desde 30 minutos antes del inicio hasta el fin. Un acceso registrado impide la cancelación básica. | Cualquier operación offline requiere diseño y aprobación posteriores |

Las decisiones se cierran antes de la primera migración o implementación que dependa de ellas. Los contratos de confirmación describen precondiciones mientras se definen las reglas comerciales.
