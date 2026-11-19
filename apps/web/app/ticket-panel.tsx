"use client";

import { Button } from "@heroui/react";
import QRCode from "qrcode";
import { useEffect, useRef, useState } from "react";
import { api, ApiError } from "./api-client";

type Ticket = { number: number; code: string; used: boolean };
type Tickets = { items: Ticket[]; next_from: number | null; quantity: number; operation_status: string };

const states: Record<string, string> = { pending: "Pendiente", checkin_partial: "Parcial", checkin_complete: "Completo" };

export default function TicketPanel({ route }: { route: string }) {
  const [page, setPage] = useState<Tickets | null>(null);
  const [selected, setSelected] = useState<number>();
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const canvas = useRef<HTMLCanvasElement>(null);
  const ticket = page?.items.find((item) => item.number === selected);

  useEffect(() => {
    if (ticket && canvas.current) {
      void QRCode.toCanvas(canvas.current, ticket.code, { width: 220, margin: 4 }).catch(() => setError("No se pudo generar el QR."));
    }
  }, [ticket]);

  async function load(from: number) {
    setBusy(true);
    setError("");
    try {
      const result = await api<Tickets>(`${route}/tickets?from=${from}`);
      setPage(result);
      setSelected(result.items[0]?.number);
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : "No se pudieron consultar los boletos.");
    } finally {
      setBusy(false);
    }
  }

  return <section className="grid gap-2">
    <Button className="voucher-print-control" variant="outline" isDisabled={busy} onPress={() => void load(1)}>Consultar boletos QR</Button>
    {error && <p role="alert">{error}</p>}
    {page && <p>Boletos emitidos {page.quantity} · Check-in {states[page.operation_status]}</p>}
    {ticket && <div className="grid gap-2">
      <label className="field voucher-print-control">Boleto<select value={ticket.number} onChange={(event) => setSelected(Number(event.target.value))}>
        {page?.items.map((item) => <option key={item.number} value={item.number}>Boleto {item.number}{item.used ? " · Utilizado" : ""}</option>)}
      </select></label>
      <p>Boleto {ticket.number} de {page?.quantity}</p>
      <canvas ref={canvas} role="img" aria-label={`Código QR del boleto ${ticket.number}`} />
      <label className="field voucher-print-control">Código del boleto<input readOnly value={ticket.code} onFocus={(event) => event.target.select()} /></label>
    </div>}
    {page?.next_from && <Button className="voucher-print-control" variant="outline" isDisabled={busy} onPress={() => void load(page.next_from!)}>Ver siguientes boletos</Button>}
  </section>;
}
