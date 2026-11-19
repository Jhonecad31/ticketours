"use client";

import { Button } from "@heroui/react";
import { useRef, useState, type FormEvent, type ReactNode } from "react";
import { api, ApiError, list, type Page } from "./api-client";

import SalesPanel from "./sales-panel";

export type Slot = {
  session_id?: string;
  resource_id?: string;
  start_at: string;
  end_at: string;
  available_quantity?: number;
};
type Selection = { session_id: string; quantity: number } | { resource_id: string; start_at: string; end_at: string };
type Quote = { id: string; variant_id: string; selection: Selection; total: string; currency: string; expires_at: string };
type Hold = { id: string; status: string; expires_at: string };
type Customer = { id: string; name: string };
type Reservation = { id: string; status: string; items: { total: string; currency: string }[] };

const states: Record<string, string> = { held: "Retenida", confirmed: "Confirmada", cancelled: "Cancelada", expired: "Vencida" };

function Field({ label, children }: { label: string; children: ReactNode }) {
  return <label className="field">{label}{children}</label>;
}

function dateValue(value: FormDataEntryValue | null) {
  const text = String(value);
  return text.length === 16 ? `${text}:00Z` : `${text}Z`;
}

export default function ReservationPanel({ scope, variantId, slots, channel }: { scope: string; variantId: string; slots: Slot[]; channel?: string }) {
  const checkout = channel ?? scope;
  const [slotIndex, setSlotIndex] = useState(0);
  const [quoted, setQuote] = useState<Quote | null>(null);
  const quote = quoted?.variant_id === variantId ? quoted : null;
  const [hold, setHold] = useState<Hold | null>(null);
  const [reservation, setReservation] = useState<Reservation | null>(null);
  const [customers, setCustomers] = useState<Page<Customer> | null>(null);
  const [customerId, setCustomerId] = useState("");
  const [minutes, setMinutes] = useState<number>();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const keys = useRef(new Map<string, string>());
  const available = slots.filter(({ available_quantity }) => available_quantity === undefined || available_quantity > 0);
  const slot = available[slotIndex];

  async function run(action: () => Promise<void>) {
    setError("");
    setBusy(true);
    try {
      await action();
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : "No se pudo completar la operación.");
    } finally {
      setBusy(false);
    }
  }

  function submit(action: (data: FormData) => Promise<void>) {
    return (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault();
      const data = new FormData(event.currentTarget);
      void run(() => action(data));
    };
  }

  function mutate<T>(path: string, body: object) {
    const signature = JSON.stringify([path, body]);
    const key = keys.current.get(signature) ?? crypto.randomUUID();
    keys.current.set(signature, key);
    return api<T>(checkout + path, body, "POST", { "Idempotency-Key": key });
  }

  async function loadCustomers() {
    const cursor = customers?.next_cursor;
    const page = await list<Customer>(scope + "/customers", cursor);
    setCustomers({ items: [...(cursor ? customers!.items : []), ...page.items], next_cursor: page.next_cursor });
  }

  return (
    <section className="grid content-start gap-4 rounded-xl border border-[#d4e0dd] p-4 lg:col-span-2">
      <h3 className="text-lg font-semibold">{channel ? "Reserva por agencia" : "Reserva directa"}</h3>
      {error && <p role="alert" className="text-red-800">{error}</p>}
      {slot && variantId && !hold && !reservation && <form className="grid gap-3" onSubmit={submit(async (data) => {
        const selection: Selection = slot.session_id
          ? { session_id: slot.session_id, quantity: Number(data.get("quantity")) }
          : { resource_id: slot.resource_id!, start_at: dateValue(data.get("from")), end_at: dateValue(data.get("to")) };
        setQuote(null);
        setQuote(await api<Quote>(checkout + "/quotes", { variant_id: variantId, selection }));
      })}>
        <Field label="Sesión o intervalo disponible"><select value={slotIndex} disabled={busy} onChange={(event) => {
          setSlotIndex(Number(event.target.value));
          setQuote(null);
        }}>
          {available.map((item, i) => <option key={`${item.session_id ?? item.resource_id}-${item.start_at}`} value={i}>
            {item.start_at.slice(0, 16).replace("T", " ")} a {item.end_at.slice(0, 16).replace("T", " ")} UTC
          </option>)}
        </select></Field>
        {slot.session_id
          ? <Field label="Cantidad"><input name="quantity" type="number" min={1} max={slot.available_quantity} defaultValue={1} required /></Field>
          : <div key={slotIndex} className="grid gap-3 sm:grid-cols-2">
              <Field label="Inicio (UTC)"><input name="from" type="datetime-local" step="1" defaultValue={slot.start_at.slice(0, 19)} required /></Field>
              <Field label="Fin (UTC)"><input name="to" type="datetime-local" step="1" defaultValue={slot.end_at.slice(0, 19)} required /></Field>
            </div>}
        <Button type="submit" isDisabled={busy}>Cotizar</Button>
      </form>}
      {quote && !hold && !reservation && <div className="flex flex-wrap items-center justify-between gap-3">
        <p>{quote.total} {quote.currency} · Vence {new Date(quote.expires_at).toLocaleString("es-MX")}</p>
        <Button isDisabled={busy} onPress={() => void run(async () => {
          setHold(await mutate<Hold>("/holds", { quote_id: quote.id, selection: quote.selection }));
        })}>Retener inventario</Button>
      </div>}
      {hold && !reservation && <div className="grid gap-3">
        <p role="status">Retención vigente hasta {new Date(hold.expires_at).toLocaleString("es-MX")}</p>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" isDisabled={busy} onPress={() => void run(loadCustomers)}>
            {customers?.next_cursor ? "Cargar más clientes" : "Consultar clientes"}
          </Button>
          <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
            await mutate(`/holds/${hold.id}/release`, {});
            setHold(null);
            setQuote(null);
          })}>Liberar retención</Button>
        </div>
        <form className="grid gap-3 sm:grid-cols-2" onSubmit={submit(async (data) => {
          const customer = await api<Customer>(scope + "/customers", Object.fromEntries(data));
          setCustomers((page) => ({ items: [customer, ...(page?.items ?? [])], next_cursor: page?.next_cursor ?? null }));
          setCustomerId(customer.id);
        })}>
          <Field label="Nombre del cliente nuevo"><input name="name" required maxLength={200} /></Field>
          <Field label="Correo (opcional)"><input name="email" type="email" maxLength={254} /></Field>
          <Button type="submit" isDisabled={busy}>Registrar cliente</Button>
        </form>
        {customers && customers.items.length > 0 && <form className="grid gap-3" onSubmit={(event) => {
          event.preventDefault();
          const data = new FormData(event.currentTarget);
          const participant = String(data.get("participant") ?? "").trim();
          void run(async () => {
            setReservation(await mutate<Reservation>("/reservations", {
              hold_id: hold.id, customer_id: data.get("customer"), participants: participant ? [{ name: participant }] : [],
            }));
          });
        }}>
          <Field label="Cliente de la reserva"><select name="customer" required value={customerId} onChange={(event) => setCustomerId(event.target.value)}>
            <option value="">Seleccionar cliente</option>
            {customers.items.map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}
          </select></Field>
          <Field label="Participante (opcional)"><input name="participant" maxLength={200} /></Field>
          <Button type="submit" isDisabled={busy}>Crear reserva</Button>
        </form>}
      </div>}
      {reservation && <div className="grid gap-3">
        <p role="status">{states[reservation.status] ?? reservation.status} · {reservation.items[0]?.total} {reservation.items[0]?.currency}</p>
        <p className="break-all text-sm">Referencia {reservation.id}</p>
        {channel && <p className="text-sm">El proveedor registra el pago y confirma la reserva. Consulta su estado en ventas por agencia.</p>}
        {!channel && <SalesPanel key={reservation.id} scope={scope} reference={reservation.id} status={reservation.status} />}
        {!channel && reservation.status === "held" && <Button isDisabled={busy} onPress={() => void run(async () => {
          setReservation(await mutate<Reservation>(`/reservations/${reservation.id}/confirm`, {}));
        })}>Confirmar reserva</Button>}
        {!channel && ["held", "confirmed"].includes(reservation.status) && <form className="grid gap-3" onSubmit={(event) => {
          event.preventDefault();
          const reason = new FormData(event.currentTarget).get("reason");
          void run(async () => {
            setReservation(await mutate<Reservation>(`/reservations/${reservation.id}/cancel`, { reason }));
          });
        }}>
          <Field label="Motivo de cancelación"><input name="reason" required maxLength={500} /></Field>
          <Button type="submit" variant="outline" isDisabled={busy}>Cancelar reserva</Button>
        </form>}
      </div>}
      {!channel && <><form className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end" onSubmit={submit(async (data) => {
        setReservation(await api<Reservation>(`${scope}/reservations/${data.get("reference")}`));
      })}>
        <Field label="Referencia de reserva"><input name="reference" required defaultValue={reservation?.id} key={reservation?.id} /></Field>
        <Button type="submit" variant="outline" isDisabled={busy}>Consultar reserva</Button>
      </form>
      <div className="border-t border-[#d4e0dd] pt-3">
        {minutes === undefined
          ? <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
              setMinutes((await api<{ hold_minutes: number }>(scope + "/reservation-settings")).hold_minutes);
            })}>Ver vigencia de retenciones</Button>
          : <form className="flex flex-wrap items-end gap-3" onSubmit={submit(async (data) => {
              const settings = await api<{ hold_minutes: number }>(scope + "/reservation-settings", { hold_minutes: Number(data.get("minutes")) }, "PATCH");
              setMinutes(settings.hold_minutes);
            })}>
              <Field label="Vigencia (minutos)"><input name="minutes" type="number" min={1} max={1440} defaultValue={minutes} required /></Field>
              <Button type="submit" variant="outline" isDisabled={busy}>Guardar vigencia</Button>
            </form>}
      </div></>}
    </section>
  );
}
