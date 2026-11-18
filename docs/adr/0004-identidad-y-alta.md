# ADR 0004 · Identidad, alta de organizaciones y sesiones

**Estado.** Aprobado para Fase 1 en D01 y D03.

- La organización es la unidad de aislamiento y administración. Marca, sucursal y sede se subordinan a ella cuando el catálogo y la operación las requieran. La identidad fiscal depende de D06.
- Laravel almacena sesiones en PostgreSQL. El navegador usa `ticketours_session`, una cookie HttpOnly, SameSite=Lax y Secure en HTTPS. Next.js reenvía las llamadas a la API bajo el mismo origen. Las mutaciones requieren `X-CSRF-TOKEN`. Se renueva la sesión al autenticar y se invalida al salir.
- El alta de una organización requiere permiso de plataforma y asigna la propiedad a la cuenta creadora en la misma transacción. La CLI provisiona la primera cuenta con este permiso. La pertenencia a otra organización se concede por invitación.
- En Fase 1 el alcance de las asignaciones es la organización. Una membresía admite varias asignaciones. Los permisos de sede y recurso se aplicarán cuando existan esos recursos. RLS queda para evaluación posterior y el acceso de soporte queda deshabilitado.
- Las invitaciones son individuales, de un solo uso, con vencimiento de 48 horas y almacenamiento de la huella. La persona autorizada comparte el código por su canal. Una cuenta existente debe iniciar sesión con el correo invitado. Para un correo nuevo se crea una cuenta al aceptar.
- Registrar las altas, invitaciones y membresías en auditoría dentro de su transacción. Las consultas están delimitadas por organización y permiso, con identificador de correlación y sin contraseñas ni códigos de invitación en eventos.
