"use client";

import { Button } from "@heroui/react";
import { useState, type FormEvent } from "react";
import { api, ApiError, list, type Page } from "./api-client";

type Settlement = { id: string; provider_id: string; agency_id: string; currency: string; total: string; status: string; payment_reference: string | null };
type Money = { currency: string; amount: string };
type Report = {
  time_zone: string; reservations: { created: number; confirmed: number; cancelled: number; expired: number };
  checkins: number; agency_sales_created: number; payments: Money[]; refunds: Money[];
  commissions_receivable: Money[]; commissions_payable: Money[]; settlements_received: Money[]; settlements_paid: Money[];
};
type Delivery = { event_id: string; status: string; attempts: number; duplicate: boolean };
type Checkin = { id: string; reservation_id: string; ticket_number: number; operation_status: string };
type Movement = { id: string; type: string; amount: string; currency: string };

const states: Record<string, string> = { reconciled: "Conciliada", approved: "Aprobada", paid: "Pagada", checkin_partial: "Parcial", checkin_complete: "Completo" };

function asUtc(value: FormDataEntryValue | null) {
  const text = String(value);
  return text.length === 16 ? `${text}:00Z` : `${text}Z`;
}

export default function OperationsPanel({ scope, organizationId }: { scope: string; organizationId: string }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [checkin, setCheckin] = useState<Checkin | null>(null);
  const [validators, setValidators] = useState<string[] | null>(null);
  const [settlements, setSettlements] = useState<Page<Settlement> | null>(null);
  const [movements, setMovements] = useState<Page<Movement> | null>(null);
  const [movementId, setMovementId] = useState("");
  const [report, setReport] = useState<Report | null>(null);
  const [delivery, setDelivery] = useState<Delivery | null>(null);
  const [eventId, setEventId] = useState("");
  const [keys, setKeys] = useState<Record<string, string>>({});
  const settlementRoute = scope + "/distribution/settlements";

  async function run(task: () => Promise<void>) {
    setBusy(true);
    setError("");
    try {
      await task();
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : "No se pudo completar la operación.");
    } finally {
      setBusy(false);
    }
  }

  function form(task: (data: FormData) => Promise<void>) {
    return (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault();
      const data = new FormData(event.currentTarget);
      void run(() => task(data));
    };
  }

  async function mutate<T>(route: string, body: object, method: "POST" | "PATCH" = "POST") {
    const attempt = JSON.stringify([route, method, body]);
    const key = keys[attempt] ?? crypto.randomUUID();
    setKeys(current => ({ ...current, [attempt]: key }));
    const response = await api<T>(route, body, method, { "Idempotency-Key": key });
    setKeys(current => Object.fromEntries(Object.entries(current).filter(([item]) => item !== attempt)));
    return response;
  }

  async function loadSettlements() {
    setSettlements(await list<Settlement>(settlementRoute));
  }

  return <section className="panel grid gap-5">
    <h2 className="text-xl font-semibold">Operación, liquidaciones e integraciones</h2>
    {error && <p role="alert" className="text-red-800">{error}</p>}
    <div className="grid gap-5 lg:grid-cols-2">
      <section className="grid content-start gap-3">
        <h3 className="font-semibold">Check-in</h3>
        <form className="grid gap-2" onSubmit={(event) => {
          event.preventDefault();
          const code = String(new FormData(event.currentTarget).get("code")).trim();
          setCheckin(null);
          void run(async () => setCheckin(await mutate<Checkin>(scope + "/operations/check-ins", { code })));
        }}>
          <label className="field">Escanear o pegar código QR<input name="code" autoComplete="off" required /></label>
          <Button type="submit" isDisabled={busy}>Registrar acceso</Button>
        </form>
        {checkin && <p role="status">Acceso registrado · boleto {checkin.ticket_number} · Check-in {states[checkin.operation_status]}</p>}
        <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
          setValidators((await api<{ items: string[] }>(scope + "/operations/validators")).items);
        })}>Gestionar validadores</Button>
        {validators && <>
          <p className="text-sm">{validators.length ? `Usuarios autorizados: ${validators.join(", ")}` : "Sin validadores adicionales."}</p>
          <form className="grid gap-2 sm:grid-cols-3 sm:items-end" onSubmit={form(async data => {
            await api(scope + "/operations/validators", { user_id: data.get("user_id"), enabled: data.get("enabled") === "grant" }, "PATCH");
            setValidators((await api<{ items: string[] }>(scope + "/operations/validators")).items);
          })}>
            <label className="field">Identificador de usuario<input name="user_id" inputMode="numeric" pattern="[1-9][0-9]*" required /></label>
            <label className="field">Permiso<select name="enabled"><option value="grant">Conceder</option><option value="revoke">Revocar</option></select></label>
            <Button type="submit" isDisabled={busy}>Guardar validador</Button>
          </form>
        </>}
      </section>
      <section className="grid content-start gap-3">
        <h3 className="font-semibold">Liquidaciones de comisión</h3>
        <Button variant="outline" isDisabled={busy} onPress={() => void run(loadSettlements)}>Consultar liquidaciones</Button>
        {settlements && <>
          <form className="grid gap-2 sm:grid-cols-3 sm:items-end" onSubmit={(event) => {
            event.preventDefault();
            const data = new FormData(event.currentTarget);
            void run(async () => {
              await mutate(settlementRoute, Object.fromEntries(data));
              await loadSettlements();
            });
          }}>
            <label className="field">Agencia para liquidar<input name="agency_id" required /></label>
            <label className="field">Moneda del corte<input name="currency" maxLength={3} pattern="[A-Z]{3}" defaultValue="MXN" required /></label>
            <Button type="submit" isDisabled={busy}>Conciliar corte</Button>
          </form>
          <ul className="grid gap-3 text-sm">{settlements.items.map((item) => <li key={item.id} className="grid gap-2 border-b border-[#d4e0dd] pb-2">
            <p className="break-all">{states[item.status]} · {item.total} {item.currency} · {item.id}</p>
            {item.payment_reference && <p className="break-all">Referencia simulada {item.payment_reference}</p>}
            <div className="flex flex-wrap gap-2">
              <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
                setMovements(await list<Movement>(settlementRoute + "/" + item.id + "/items"));
                setMovementId(item.id);
              })}>Ver movimientos</Button>
              {item.provider_id === organizationId && item.status !== "paid" && [
                ["reconciled", "Volver a conciliar"],
                item.status === "reconciled" ? ["approved", "Aprobar liquidación"] : ["paid", "Pagar en simulador"],
              ].map(([status, label]) => <Button key={status} variant="outline" isDisabled={busy} onPress={() => void run(async () => {
                await mutate(settlementRoute + "/" + item.id, { status }, "PATCH");
                await loadSettlements();
              })}>{label}</Button>)}
            </div>
            {movements && movementId === item.id && <>
              <ul>{movements.items.map(row => <li key={row.id}>{row.type === "accrual" ? "Devengo" : "Reversión"} · {row.amount} {row.currency}</li>)}</ul>
              {movements.next_cursor && <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
                const next = await list<Movement>(settlementRoute + "/" + item.id + "/items", movements.next_cursor);
                setMovements({ items: [...movements.items, ...next.items], next_cursor: next.next_cursor });
              })}>Ver más movimientos</Button>}
            </>}
          </li>)}</ul>
          {settlements.next_cursor && <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
            const next = await list<Settlement>(settlementRoute, settlements.next_cursor);
            setSettlements({ items: [...settlements.items, ...next.items], next_cursor: next.next_cursor });
          })}>Ver más liquidaciones</Button>}
        </>}
      </section>
      <section className="grid content-start gap-3">
        <h3 className="font-semibold">Reporte por período</h3>
        <form className="grid gap-2 sm:grid-cols-2" onSubmit={form(async data => {
          const query = new URLSearchParams({ from: asUtc(data.get("from")), to: asUtc(data.get("to")) });
          setReport(await api<Report>(scope + "/reports/summary?" + query));
        })}>
          <label className="field">Inicio del reporte (UTC)<input name="from" type="datetime-local" required /></label>
          <label className="field">Fin del reporte (UTC)<input name="to" type="datetime-local" required /></label>
          <Button type="submit" isDisabled={busy}>Generar reporte</Button>
        </form>
        {report && <div className="grid gap-2 text-sm">
          <p>{report.reservations.created} reservas creadas · {report.reservations.confirmed} confirmadas · {report.reservations.cancelled} canceladas · {report.reservations.expired} vencidas</p>
          <p>{report.checkins} accesos · {report.agency_sales_created} ventas propias como agencia</p>
          {([["Cobros", report.payments], ["Reembolsos", report.refunds], ["Comisión por cobrar", report.commissions_receivable],
            ["Comisión por pagar", report.commissions_payable], ["Liquidaciones recibidas", report.settlements_received], ["Liquidaciones pagadas", report.settlements_paid]] as [string, Money[]][])
            .map(([label, items]) => <p key={label}>{label} · {items.length ? items.map((item) => item.amount + " " + item.currency).join(", ") : "Sin movimientos"}</p>)}
        </div>}
      </section>
      <section className="grid content-start gap-3">
        <h3 className="font-semibold">Webhook de prueba</h3>
        <form className="grid gap-2" onSubmit={form(async data => {
          const id = eventId || crypto.randomUUID();
          setEventId(id);
          setDelivery(await api<Delivery>(scope + "/integrations/simulate", { id, reservation_id: data.get("reservation_id") }));
        })}>
          <label className="field">Reserva para el webhook<input name="reservation_id" required /></label>
          <label className="field">Id del evento (opcional)<input value={eventId} onChange={event => setEventId(event.target.value)} /></label>
          <Button type="submit" isDisabled={busy}>Enviar webhook simulado</Button>
        </form>
        {delivery && <p role="status">{delivery.status === "processed" ? "Procesado" : "Pendiente"} · Intentos {delivery.attempts}{delivery.duplicate ? " · Evento repetido" : ""}</p>}
      </section>
    </div>
  </section>;
}
