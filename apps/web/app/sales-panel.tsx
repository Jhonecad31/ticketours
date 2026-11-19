"use client";

import { Button } from "@heroui/react";
import { useEffect, useRef, useState } from "react";
import { api, ApiError } from "./api-client";
import TicketPanel from "./ticket-panel";

type Order = {
  status: string;
  payment_status: string;
  total: string;
  paid: string;
  refunded: string;
  balance: string;
  currency: string;
  allows_partial_payment: boolean;
  voucher_available: boolean;
};

type Voucher = { id: string; reservation_id: string; organization: string; customer: string; start_at: string; end_at: string; time_zone: string; order: Order };

const labels: Record<string,string> = { pending: "Pendiente", partial: "Parcial", paid: "Pagado", refunded: "Reembolsado" };

export default function SalesPanel({ scope, reference, status }: { scope: string; reference: string; status: string }) {
  const route = `${scope}/reservations/${reference}`;
  const [order, setOrder] = useState<Order | null>(null);
  const [voucher, setVoucher] = useState<Voucher | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const keys = useRef(new Map<string, string>());

  useEffect(() => {
    let active = true;
    async function load() {
      try {
        const result = await api<Order>(route + "/order");
        if (active) setOrder(result);
      } catch (cause) {
        if (active) setError(cause instanceof ApiError ? cause.message : "No se pudo consultar el pedido.");
      }
    }
    void load();
    return () => { active = false; };
  }, [route, status]);

  async function run(action: () => Promise<void>) {
    setBusy(true);
    setError("");
    try {
      await action();
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : "No se pudo completar la operación.");
    } finally {
      setBusy(false);
    }
  }

  return <section className="grid gap-3 border-t border-[#d4e0dd] pt-3">
    <h4 className="font-semibold">Pedido y pagos simulados</h4>
    {error && <p role="alert" className="text-red-800">{error}</p>}
    {!order && !error && <p role="status">Consultando pedido…</p>}
    {order && <>
      <dl className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
        <div><dt>Total</dt><dd>{order.total} {order.currency}</dd></div>
        <div><dt>Abonado</dt><dd>{order.paid} {order.currency}</dd></div>
        <div><dt>Saldo</dt><dd>{order.balance} {order.currency}</dd></div>
        <div><dt>Estado del pago</dt><dd>{labels[order.payment_status]}</dd></div>
      </dl>
      {order.payment_status === "refunded" && <p>Reembolso simulado de {order.refunded} {order.currency}.</p>}
      {status === "held" && <p className="text-sm">{order.allows_partial_payment ? "La política permite confirmar desde el 50 %." : "La política exige el pago total para confirmar."}</p>}
      {["held", "confirmed"].includes(status) && /[1-9]/.test(order.balance) && <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const amount = String(new FormData(form).get("amount"));
        void run(async () => {
          const key = keys.current.get(amount) ?? crypto.randomUUID();
          keys.current.set(amount, key);
          const response = await api<{ payment_id: string; order: Order }>(route + "/payments", { amount }, "POST", { "Idempotency-Key": key });
          keys.current.delete(amount);
          setOrder(response.order);
          setVoucher(null);
          form.reset();
        });
      }}>
        <label className="field">Importe del abono ({order.currency})<input name="amount" inputMode="decimal" required /></label>
        <Button type="submit" isDisabled={busy}>Registrar abono simulado</Button>
      </form>}
      {status === "confirmed" && order.voucher_available && <Button variant="outline" isDisabled={busy} onPress={() => void run(async () => {
        setVoucher(await api<Voucher>(route + "/voucher"));
      })}>Consultar voucher</Button>}
      {status === "confirmed" && voucher && <article className="ticketours-voucher grid gap-3 rounded-xl border p-4">
        <h4 className="text-lg font-semibold">Voucher de prueba · {voucher.organization}</h4>
        <p>{voucher.customer}</p>
        <p>{new Date(voucher.start_at).toLocaleString("es-MX", { timeZone: voucher.time_zone })} — {new Date(voucher.end_at).toLocaleString("es-MX", { timeZone: voucher.time_zone })} ({voucher.time_zone})</p>
        <p className="break-all text-sm">Reserva {voucher.reservation_id}</p>
        <p className="break-all text-sm">Voucher {voucher.id}</p>
        <p>Saldo pendiente {voucher.order.balance} {voucher.order.currency}</p>
        <TicketPanel route={route} />
        <Button className="voucher-print-control" onPress={() => window.print()}>Imprimir voucher</Button>
      </article>}
    </>}
  </section>;
}
