"use client";

import { Button } from "@heroui/react";
import { useState, type FormEvent } from "react";
import { api, ApiError, list, type Page } from "./api-client";
import ReservationPanel, { type Slot } from "./reservation-panel";

type Agreement = { id: string; status: string; provider_id: string; agency_id: string; commission_percent: string };
type Offer = { id: string; provider_id: string; agency_id: string; variant_id: string; product_name: string; variant_name: string; amount: string; currency: string; enabled: boolean };
type Variant = { id: string; name: string };
type Rate = { id: string; version: number; amount: string; currency: string };
type Sale = { id: string; reservation_id: string; reservation_status: string; order: { total: string; currency: string; paid: string; refunded: string; payment_status: string } };
type Balance = { direction: string; currency: string; amount: string };
type Movement = { id: string; type: string; amount: string; currency: string };
const statuses: Record<string, string> = { proposed: "Propuesto", active: "Activo", suspended: "Suspendido", terminated: "Terminado", held: "Retenida", confirmed: "Confirmada", cancelled: "Cancelada", expired: "Vencida", pending: "Pendiente", partial: "Parcial", paid: "Pagado", refunded: "Reembolsado" };

export default function DistributionPanel({ scope, organizationId }: { scope: string; organizationId: string }) {
  const base = scope + "/distribution";
  const [agreements, setAgreements] = useState<Page<Agreement> | null>(null);
  const [offers, setOffers] = useState<Page<Offer> | null>(null);
  const [sales, setSales] = useState<Page<Sale> | null>(null);
  const [balances, setBalances] = useState<Balance[] | null>(null);
  const [movements, setMovements] = useState<Page<Movement> | null>(null);
  const [contract, setContract] = useState("");
  const [variants, setVariants] = useState<Variant[]>([]);
  const [variant, setVariant] = useState("");
  const [rates, setRates] = useState<Rate[]>([]);
  const [selected, setSelected] = useState("");
  const [slots, setSlots] = useState<Slot[]>([]);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ text: string; error?: boolean }>();
  const agreement = agreements?.items.find(item => item.id === contract);
  const offer = offers?.items.find(item => item.id === selected);
  const channel = offer?.agency_id === organizationId ? base + "/offers/" + offer.id : undefined;

  async function run(task: () => Promise<void>, success?: string) {
    setBusy(true);
    setMessage(undefined);
    try {
      await task();
      if (success) setMessage({ text: success });
    } catch (cause) {
      setMessage({ text: cause instanceof ApiError ? cause.message : "No se pudo completar la operación.", error: true });
    } finally {
      setBusy(false);
    }
  }

  function form(action: (data: FormData) => Promise<void>, success?: string) {
    return (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault();
      const data = new FormData(event.currentTarget);
      void run(() => action(data), success);
    };
  }

  async function loadRates(id: string) {
    setVariant(id);
    setRates(id ? (await api<Page<Rate>>(`${scope}/rates?variant_id=${id}&limit=100`)).items : []);
  }

  async function loadAgreements() {
    const page = await list<Agreement>(base + "/agreements");
    setAgreements(page);
    setContract(page.items.find(item => item.id === contract)?.id ?? page.items[0]?.id ?? "");
  }

  async function loadOffers() {
    const page = await list<Offer>(base + "/offers");
    setOffers(page);
    setSlots([]);
    setSelected(page.items.find(item => item.id === selected)?.id ?? page.items[0]?.id ?? "");
  }

  return <section className="panel grid gap-4">
    <h2 className="text-xl font-semibold">Agencias y distribución</h2>
    <p className="break-all text-sm">Identificador de esta organización · {organizationId}</p>
    {message && <p role={message.error ? "alert" : "status"} className={message.error ? "text-red-800" : "text-emerald-800"}>{message.text}</p>}
    <div className="flex flex-wrap gap-2">
      <Button variant="outline" isDisabled={busy} onPress={() => void run(loadAgreements)}>Gestionar convenios</Button>
      <Button variant="outline" isDisabled={busy} onPress={() => void run(loadOffers)}>Consultar ofertas</Button>
      <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => setSales(await list<Sale>(base + "/sales")))}>Consultar ventas por agencia</Button>
      <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
        const [balance, entries] = await Promise.all([api<{ items: Balance[] }>(base + "/commission-balances"), list<Movement>(base + "/commissions")]);
        setBalances(balance.items);
        setMovements(entries);
      })}>Consultar comisiones</Button>
    </div>
    {agreements && <div className="grid gap-4">
      <form className="grid gap-2 sm:grid-cols-3 sm:items-end" onSubmit={form(async data => {
        const created = await api<Agreement>(base + "/agreements", Object.fromEntries(data));
        setAgreements(current => current ? { ...current, items: [created, ...current.items] } : current);
        setContract(created.id);
      }, "Convenio propuesto.")}>
        <label className="field">Identificador de la agencia<input name="agency_id" required /></label>
        <label className="field">Comisión (%)<input name="commission_percent" required inputMode="decimal" /></label>
        <Button type="submit" isDisabled={busy}>Proponer convenio</Button>
      </form>
      {!!agreements.items.length && <label className="field">Convenio<select value={contract} onChange={event => setContract(event.target.value)}>
        {agreements.items.map(item => <option key={item.id} value={item.id}>{statuses[item.status]} · {item.commission_percent} % · {item.provider_id === organizationId ? item.agency_id : item.provider_id}</option>)}
      </select></label>}
      {agreements.next_cursor && <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
        const page = await list<Agreement>(base + "/agreements", agreements.next_cursor);
        setAgreements({ items: [...agreements.items, ...page.items], next_cursor: page.next_cursor });
      })}>Cargar más convenios</Button>}
      {agreement && <div className="flex flex-wrap gap-2">
        {(agreement.status === "proposed" && agreement.agency_id === organizationId ? [["active", "Aceptar convenio"]]
          : agreement.status === "active" ? [["suspended", "Suspender convenio"]]
          : agreement.status === "suspended" && agreement.provider_id === organizationId ? [["proposed", "Volver a proponer"]] : [])
          .concat(agreement.status !== "terminated" ? [["terminated", "Terminar convenio"]] : [])
          .map(([status, label]) => <Button key={status} variant="outline" isDisabled={busy} onPress={() => void run(async () => {
            const updated = await api<Agreement>(base + "/agreements/" + agreement.id, { status }, "PATCH");
            setAgreements(current => current ? { ...current, items: current.items.map(item => item.id === updated.id ? updated : item) } : current);
          }, "Convenio actualizado.")}>{label}</Button>)}
        {agreement.provider_id === organizationId && agreement.status === "active" && <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
          const data = await api<Page<Variant>>(scope + "/product-variants?limit=100");
          setVariants(data.items);
          await loadRates(data.items[0]?.id ?? "");
        })}>Preparar oferta</Button>}
      </div>}
      {agreement?.provider_id === organizationId && agreement.status === "active" && variants.length > 0 &&
        <form className="grid gap-2 sm:grid-cols-3 sm:items-end" onSubmit={form(async data => {
          await api(base + "/agreements/" + agreement.id + "/offers", Object.fromEntries(data));
          await loadOffers();
        }, "Oferta publicada.")}>
          <label className="field">Variante de la oferta<select name="variant_id" value={variant} onChange={event => void run(() => loadRates(event.target.value))}>
            {variants.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
          </select></label>
          <label className="field">Tarifa autorizada<select name="rate_version_id" required>
            {rates.map(item => <option key={item.id} value={item.id}>Versión {item.version} · {item.amount} {item.currency}</option>)}
          </select></label>
          <Button type="submit" isDisabled={busy || rates.length === 0}>Publicar oferta</Button>
        </form>}
    </div>}
    {offers && <div className="grid gap-3">
      {offers.items.length === 0 ? <p>Sin ofertas visibles.</p> : <label className="field">Oferta<select value={selected} onChange={event => {
        setSelected(event.target.value);
        setSlots([]);
      }}>{offers.items.map(item => <option key={item.id} value={item.id}>{item.product_name} · {item.variant_name} · {item.amount} {item.currency}</option>)}</select></label>}
      {offers.next_cursor && <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
        const page = await list<Offer>(base + "/offers", offers.next_cursor);
        setOffers({ items: [...offers.items, ...page.items], next_cursor: page.next_cursor });
      })}>Cargar más ofertas</Button>}
      {offer?.provider_id === organizationId && <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
        await api(base + "/offers/" + offer.id, { enabled: !offer.enabled }, "PATCH");
        await loadOffers();
      })}>{offer.enabled ? "Deshabilitar oferta" : "Habilitar oferta"}</Button>}
      {channel && offer && <>
        <form className="grid gap-2 sm:grid-cols-3 sm:items-end" onSubmit={form(async data => {
          const query = new URLSearchParams({ from: String(data.get("from")) + ":00Z", to: String(data.get("to")) + ":00Z" });
          setSlots((await api<{ items: Slot[] }>(channel + "/availability?" + query)).items);
        })}>
          <label className="field">Inicio para oferta (UTC)<input name="from" type="datetime-local" required /></label>
          <label className="field">Fin para oferta (UTC)<input name="to" type="datetime-local" required /></label>
          <Button type="submit" isDisabled={busy}>Consultar cupo del proveedor</Button>
        </form>
        <ReservationPanel key={offer.id} scope={scope} variantId={offer.variant_id} slots={slots} channel={channel} />
      </>}
    </div>}
    {sales && <section className="grid gap-2">
      <h3 className="font-semibold">Ventas por agencia</h3>
      {sales.items.length === 0 && <p>Sin ventas por agencia.</p>}
      <ul className="grid gap-2 text-sm">{sales.items.map(item => <li key={item.id}>
        <span className="break-all">Reserva {item.reservation_id}</span> · {statuses[item.reservation_status]} · {item.order.total} {item.order.currency} · {statuses[item.order.payment_status]} · abonado {item.order.paid} · reembolsado {item.order.refunded}
      </li>)}</ul>
      {sales.next_cursor && <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
        const page = await list<Sale>(base + "/sales", sales.next_cursor);
        setSales({ items: [...sales.items, ...page.items], next_cursor: page.next_cursor });
      })}>Cargar más ventas</Button>}
    </section>}
    {balances && <section className="grid gap-2">
      <h3 className="font-semibold">Saldo de comisiones</h3>
      {balances.length === 0 && <p>Sin movimientos de comisión.</p>}
      <ul className="text-sm">{balances.map(item => <li key={item.direction + item.currency}>{item.direction === "receivable" ? "Por cobrar" : "Por pagar"} · {item.amount} {item.currency}</li>)}</ul>
      <ul className="text-sm">{movements?.items.map(item => <li key={item.id}>{item.type === "accrual" ? "Devengo" : "Reversión"} · {item.amount} {item.currency}</li>)}</ul>
      {movements?.next_cursor && <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
        const page = await list<Movement>(base + "/commissions", movements.next_cursor);
        setMovements({ items: [...movements.items, ...page.items], next_cursor: page.next_cursor });
      })}>Cargar más movimientos</Button>}
    </section>}
  </section>;
}
