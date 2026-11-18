# ADR 0002 · Aislamiento y autorización

**Decisión.** Tablas compartidas con `organization_id` en datos privados y claves foráneas compuestas para referencias internas. El identificador de ruta selecciona la organización solicitada. El servidor obtiene la identidad autenticada y verifica membresía activa, permiso y alcance antes de consultar o modificar datos. Los trabajos deben resolver y validar su contexto explícitamente.

Las asignaciones de rol se aplican por organización, sede o recurso. Los roles de plataforma usan autorización específica. El acceso de soporte requiere autorización y un evento de auditoría con actor y contexto. Las operaciones entre empresas se habilitarán mediante convenios explícitos y el proveedor conservará el control del inventario.

**Comprobación.** Probar consultas, mutaciones y referencias cruzadas con dos organizaciones, además de un usuario con permisos distintos en dos membresías. Aplicar el mismo alcance a accesos indirectos y consultas de auditoría.

**Pendientes.** D03 resolvió sesión y alta. RLS queda para evaluación y soporte queda deshabilitado hasta acordar su autorización. Consulta [ADR 0004](0004-identidad-y-alta.md). D02 define el acceso a clientes compartidos.
