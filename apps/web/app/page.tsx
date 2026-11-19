"use client";

import { Button } from "@heroui/react";
import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from "react";
import { api, ApiError, list, type Page } from "./api-client";
import CatalogPanel from "./catalog-panel";
import DistributionPanel from "./distribution-panel";
import OperationsPanel from "./operations-panel";

type User = { name: string; can_create_organizations: boolean };
type Organization = { id: string; name: string; time_zone: string };
type Membership = { id: string; user_id: string; active: boolean; roles: string[] };
type AuditEvent = { id: string; action: string; entity_type: string; occurred_at: string };
type Invitation = { token: string; expires_at: string };

const roleLabels: Record<string, string> = {
  owner: "Propietario",
  admin: "Administrador",
  booking_agent: "Agente de reservaciones",
};

const actionLabels: Record<string, string> = {
  "organization.created": "Organización creada",
  "invitation.created": "Invitación generada",
  "invitation.accepted": "Invitación aceptada",
  "membership.created": "Membresía creada",
  "user.created": "Cuenta creada",
};

function Panel({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="panel grid content-start gap-5">
      <h2 className="text-xl font-semibold tracking-tight">{title}</h2>
      {children}
    </section>
  );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return <label className="field">{label}{children}</label>;
}

function errorMessage(error: unknown) {
  if (!(error instanceof ApiError)) return "No se pudo completar la operación.";
  return error.correlationId ? `${error.message} Referencia ${error.correlationId}` : error.message;
}

export default function Home() {
  const [user, setUser] = useState<User | null>(null);
  const [organizations, setOrganizations] = useState<Page<Organization>>({ items: [], next_cursor: null });
  const [selected, setSelected] = useState("");
  const [members, setMembers] = useState<Page<Membership> | null>(null);
  const [events, setEvents] = useState<Page<AuditEvent> | null>(null);
  const [invitation, setInvitation] = useState<Invitation | null>(null);
  const [accepting, setAccepting] = useState(false);
  const [busy, setBusy] = useState(false);
  const [ready, setReady] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const selection = organizations.items.find(({ id }) => id === selected);
  const scope = selected ? `/organizations/${selected}` : "";

  const changeOrganization = useCallback((id: string) => {
    setSelected(id);
    setMembers(null);
    setEvents(null);
    setInvitation(null);
  }, []);

  const refresh = useCallback(async (preferred?: Organization) => {
    const result = await list<Organization>("/organizations");
    if (preferred && !result.items.some(({ id }) => id === preferred.id)) {
      result.items.unshift(preferred);
    }
    setOrganizations(result);
    changeOrganization(preferred?.id ?? result.items[0]?.id ?? "");
  }, [changeOrganization]);

  useEffect(() => {
    let current = true;
    async function start() {
      try {
        const session = await api<User>("/auth/me");
        if (!current) return;
        setUser(session);
        await refresh();
      } catch (cause) {
        if (current && !(cause instanceof ApiError && cause.status === 401)) setError(errorMessage(cause));
      } finally {
        if (current) setReady(true);
      }
    }
    void start();
    return () => { current = false; };
  }, [refresh]);

  async function run(task: () => Promise<void>) {
    setBusy(true);
    setError("");
    setNotice("");
    try {
      await task();
    } catch (cause) {
      setError(errorMessage(cause));
    } finally {
      setBusy(false);
    }
  }

  function form(action: (data: FormData) => Promise<void>) {
    return (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault();
      const data = new FormData(event.currentTarget);
      void run(() => action(data));
    };
  }

  return (
    <div className="min-h-screen">
      <header className="border-b border-[#d4e0dd] bg-white">
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-5">
          <span className="text-2xl font-bold tracking-tight">Ticketours</span>
          {user && (
            <div className="flex flex-wrap items-center gap-3 text-sm">
              <span>{user.name}</span>
              <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
                await api<void>("/auth/logout", {});
                setUser(null);
                setAccepting(false);
                setOrganizations({ items: [], next_cursor: null });
                changeOrganization("");
              })}>Cerrar sesión</Button>
            </div>
          )}
        </div>
      </header>
      <main className="mx-auto grid max-w-6xl gap-6 px-6 py-10">
        {error && <p role="alert" className="rounded-xl bg-red-100 p-4 text-sm text-red-900">{error}</p>}
        {notice && <p role="status" className="rounded-xl bg-emerald-100 p-4 text-sm text-emerald-900">{notice}</p>}
        {!ready && <p role="status">Consultando sesión…</p>}
        {ready && !user && !accepting && (
          <div className="max-w-md">
            <Panel title="Iniciar sesión">
              <form className="grid gap-4" onSubmit={form(async (data) => {
                const session = await api<User>("/auth/login", Object.fromEntries(data));
                setUser(session);
                await refresh();
              })}>
                <Field label="Correo"><input name="email" type="email" autoComplete="username" required /></Field>
                <Field label="Contraseña"><input name="password" type="password" autoComplete="current-password" required /></Field>
                <Button type="submit" isDisabled={busy}>Entrar</Button>
              </form>
              <Button variant="outline" isDisabled={busy} onPress={() => { setError(""); setAccepting(true); }}>Aceptar invitación</Button>
            </Panel>
          </div>
        )}
        {ready && accepting && (
          <div className="max-w-lg">
            <Panel title="Aceptar invitación">
              {!user && <p className="text-sm">Si ya tienes una cuenta, inicia sesión con el correo invitado antes de aceptar.</p>}
              <form className="grid gap-4" onSubmit={form(async (data) => {
                await api<Membership>("/invitations/accept", Object.fromEntries(data));
                setAccepting(false);
                setNotice(user ? "Membresía activada." : "Cuenta creada. Ahora puedes iniciar sesión.");
                if (user) await refresh();
              })}>
                <Field label="Código de invitación"><input name="token" required pattern="[a-f0-9]{64}" autoComplete="off" /></Field>
                {!user && <>
                  <Field label="Nombre"><input name="name" autoComplete="name" maxLength={200} required /></Field>
                  <Field label="Contraseña"><input name="password" type="password" autoComplete="new-password" minLength={12} maxLength={72} required /></Field>
                </>}
                <Button type="submit" isDisabled={busy}>Aceptar</Button>
              </form>
              <Button variant="outline" isDisabled={busy} onPress={() => setAccepting(false)}>Volver</Button>
            </Panel>
          </div>
        )}
        {ready && user && !accepting && (
          <>
            <div className="flex flex-wrap items-center justify-between gap-4">
              <h1 className="text-4xl font-semibold tracking-tight">Organizaciones</h1>
              <Button variant="outline" isDisabled={busy} onPress={() => { setError(""); setNotice(""); setAccepting(true); }}>Aceptar invitación</Button>
            </div>
            <div className="grid gap-6 md:grid-cols-2">
              <Panel title="Cambiar de organización">
                {organizations.items.length ? (
                  <Field label="Organización activa">
                    <select value={selected} disabled={busy} onChange={(event) => {
                      setError("");
                      setNotice("");
                      changeOrganization(event.target.value);
                    }}>
                      {organizations.items.map((org) => <option key={org.id} value={org.id}>{org.name}</option>)}
                    </select>
                  </Field>
                ) : <p className="text-sm">Todavía no tienes organizaciones.</p>}
                {selection && <p className="text-sm">Zona horaria {selection.time_zone}</p>}
                {organizations.next_cursor && <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
                  const next = await list<Organization>("/organizations", organizations.next_cursor);
                  const ids = new Set(organizations.items.map(({ id }) => id));
                  setOrganizations({ items: [...organizations.items, ...next.items.filter(({ id }) => !ids.has(id))], next_cursor: next.next_cursor });
                })}>Cargar más organizaciones</Button>}
              </Panel>
              {user.can_create_organizations && (
                <Panel title="Crear organización">
                  <form className="grid gap-4" onSubmit={form(async (data) => {
                    const organization = await api<Organization>("/organizations", Object.fromEntries(data));
                    await refresh(organization);
                    setNotice("Organización creada.");
                  })}>
                    <Field label="Nombre"><input name="name" required maxLength={200} /></Field>
                    <Field label="Zona horaria IANA"><input name="time_zone" required defaultValue="America/Cancun" /></Field>
                    <Button type="submit" isDisabled={busy}>Crear organización</Button>
                  </form>
                </Panel>
              )}
              {scope && <>
                <div className="md:col-span-2"><CatalogPanel key={selected} scope={scope} /></div>
                <div className="md:col-span-2"><DistributionPanel key={selected} scope={scope} organizationId={selected} /></div>
                <div className="md:col-span-2"><OperationsPanel key={selected} scope={scope} organizationId={selected} /></div>
                <Panel title="Membresías">
                  <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
                    setMembers(await list<Membership>(`${scope}/memberships`));
                  })}>Consultar membresías</Button>
                  {members && <ul className="grid gap-2 text-sm">
                    {members.items.map((member) => <li key={member.id} className="border-b border-[#d4e0dd] pb-2">
                      Usuario {member.user_id} · {member.roles.map((role) => roleLabels[role] ?? role).join(", ")} · {member.active ? "Activa" : "Inactiva"}
                    </li>)}
                  </ul>}
                  {members?.next_cursor && <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
                    const next = await list<Membership>(`${scope}/memberships`, members.next_cursor);
                    setMembers({ items: [...members.items, ...next.items], next_cursor: next.next_cursor });
                  })}>Cargar más membresías</Button>}
                </Panel>
                <Panel title="Invitar a un miembro">
                  <form className="grid gap-4" onSubmit={form(async (data) => {
                    setInvitation(null);
                    setInvitation(await api<Invitation>(`${scope}/invitations`, Object.fromEntries(data)));
                  })}>
                    <Field label="Correo"><input name="email" type="email" required maxLength={254} /></Field>
                    <Field label="Rol"><select name="role">
                      <option value="booking_agent">Agente de reservaciones</option>
                      <option value="admin">Administrador</option>
                    </select></Field>
                    <Button type="submit" isDisabled={busy}>Generar invitación</Button>
                  </form>
                  {invitation && <div className="grid gap-2">
                    <p className="text-sm">Comparte este código con la persona invitada. Vence el {new Date(invitation.expires_at).toLocaleString("es-MX")}.</p>
                    <Field label="Código generado"><input readOnly value={invitation.token} onFocus={(event) => event.target.select()} /></Field>
                  </div>}
                </Panel>
                <Panel title="Auditoría">
                  <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
                    setEvents(await list<AuditEvent>(`${scope}/audit-events`));
                  })}>Consultar actividad</Button>
                  {events && <ul className="grid gap-2 text-sm">
                    {events.items.map((event) => <li key={event.id} className="border-b border-[#d4e0dd] pb-2">
                      {actionLabels[event.action] ?? event.action} · <time dateTime={event.occurred_at}>{new Date(event.occurred_at).toLocaleString("es-MX")}</time>
                    </li>)}
                  </ul>}
                  {events?.next_cursor && <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
                    const next = await list<AuditEvent>(`${scope}/audit-events`, events.next_cursor);
                    setEvents({ items: [...events.items, ...next.items], next_cursor: next.next_cursor });
                  })}>Cargar más eventos</Button>}
                </Panel>
              </>}
            </div>
          </>
        )}
      </main>
    </div>
  );
}
