"use client";

import { Button } from "@heroui/react";
import { useState, type FormEvent, type ReactNode } from "react";
import { api, ApiError, type Page } from "./api-client";
import ReservationPanel, { type Slot } from "./reservation-panel";

type Item = { id: string; name: string };
type Product = Item & { status: string };
type Variant = Item & { modality: "session" | "exclusive_resource" };
type Availability = {
  time_zone: string;
  items: Slot[];
};

const statusNames: Record<string, string> = {
  draft: "Borrador",
  review: "En revisión",
  published: "Publicado",
  suspended: "Suspendido",
  archived: "Archivado",
};

const transitions: Record<string, string[]> = {
  draft: ["review", "published", "archived"],
  review: ["draft", "published", "archived"],
  published: ["suspended"],
  suspended: ["review", "published", "archived"],
  archived: [],
};

function Field({ label, children }: { label: string; children: ReactNode }) {
  return <label className="field">{label}{children}</label>;
}

function Period() {
  return (
    <>
      <Field label="Inicio (UTC)"><input name="start_at" type="datetime-local" required /></Field>
      <Field label="Fin (UTC)"><input name="end_at" type="datetime-local" required /></Field>
    </>
  );
}

function utcFields(data: FormData) {
  return { start_at: `${data.get("start_at")}:00Z`, end_at: `${data.get("end_at")}:00Z` };
}

async function options<T>(route: string): Promise<T[]> {
  const [path, filter = ""] = route.split("?");
  const params = new URLSearchParams(filter);
  params.set("limit", "100");
  const items: T[] = [];
  do {
    const page = await api<Page<T>>(`${path}?${params}`);
    items.push(...page.items);
    if (!page.next_cursor) return items;
    params.set("cursor", page.next_cursor);
  } while (params.has("cursor"));
  return items;
}

export default function CatalogPanel({ scope }: { scope: string }) {
  const [products, setProducts] = useState<Product[]>([]);
  const [productId, setProductId] = useState("");
  const [variants, setVariants] = useState<Variant[]>([]);
  const [variantId, setVariantId] = useState("");
  const [resources, setResources] = useState<Item[]>([]);
  const [availability, setAvailability] = useState<Availability | null>(null);
  const [loaded, setLoaded] = useState(false);
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState<{ text: string; error: boolean }>();

  const product = products.find((item) => item.id === productId);
  const variant = variants.find((item) => item.id === variantId);

  async function run(task: () => Promise<void>) {
    setBusy(true);
    setFeedback(undefined);
    try {
      await task();
    } catch (cause) {
      const message = cause instanceof ApiError ? cause.message : "No se pudo completar la operación.";
      setFeedback({ text: message, error: true });
    } finally {
      setBusy(false);
    }
  }

  function form(action: (data: FormData) => Promise<void>, message?: string) {
    return (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault();
      const data = new FormData(event.currentTarget);
      void run(async () => {
        await action(data);
        if (message) setFeedback({ text: message, error: false });
      });
    };
  }

  async function selectProduct(id: string) {
    setProductId(id);
    setVariants([]);
    setVariantId("");
    setAvailability(null);
    if (!id) return;
    const items = await options<Variant>(`${scope}/product-variants?product_id=${id}`);
    setVariants(items);
    setVariantId(items[0]?.id ?? "");
  }

  async function loadCatalog() {
    const [catalog, inventory] = await Promise.all([options<Product>(`${scope}/products`), options<Item>(`${scope}/resources`)]);
    setProducts(catalog);
    setResources(inventory);
    await selectProduct(catalog[0]?.id ?? "");
    setLoaded(true);
  }

  return (
    <section className="panel grid gap-5">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <h2 className="text-xl font-semibold">Catálogo, tarifas y disponibilidad</h2>
        <Button variant="outline" isDisabled={busy} onPress={() => void run(loadCatalog)}>{loaded ? "Actualizar catálogo" : "Abrir catálogo"}</Button>
      </div>
      {feedback && <p role={feedback.error ? "alert" : "status"} className={feedback.error ? "text-red-800" : "text-emerald-800"}>{feedback.text}</p>}
      {loaded && <div className="grid gap-6 lg:grid-cols-2">
        <form className="grid content-start gap-3" onSubmit={form(async (data) => {
          const created = await api<Product>(`${scope}/products`, Object.fromEntries(data));
          setProducts((current) => [...current, created]);
          await selectProduct(created.id);
        }, "Producto creado.")}>
          <Field label="Nombre del producto"><input name="name" required maxLength={200} /></Field>
          <Button type="submit" isDisabled={busy}>Crear producto</Button>
        </form>
        <form className="grid content-start gap-3" onSubmit={form(async (data) => {
          const created = await api<Item>(`${scope}/resources`, Object.fromEntries(data));
          setResources((current) => [...current, created]);
        }, "Recurso creado.")}>
          <Field label="Nombre del recurso exclusivo"><input name="name" required maxLength={200} /></Field>
          <Button type="submit" isDisabled={busy}>Crear recurso</Button>
        </form>
        {products.length > 0 && <Field label="Producto">
          <select value={productId} disabled={busy} onChange={(event) => void run(() => selectProduct(event.target.value))}>
            {products.map((item) => <option key={item.id} value={item.id}>{item.name} · {statusNames[item.status]}</option>)}
          </select>
        </Field>}
        {product && transitions[product.status]?.length > 0 && <form key={product.id + product.status} className="grid content-start gap-3" onSubmit={form(async (data) => {
          const updated = await api<Product>(`${scope}/products/${product.id}/status`, Object.fromEntries(data), "PATCH");
          setProducts((current) => current.map((item) => item.id === updated.id ? updated : item));
          setAvailability(null);
        }, "Estado actualizado.")}>
          <Field label="Nuevo estado"><select name="status">
            {transitions[product.status].map((value) => <option key={value} value={value}>{statusNames[value]}</option>)}
          </select></Field>
          <Button type="submit" isDisabled={busy}>Cambiar estado</Button>
        </form>}
        {product && product.status !== "archived" && <>
          {product.status !== "published" && <form className="grid content-start gap-3" onSubmit={form(async (data) => {
            const created = await api<Variant>(`${scope}/product-variants`, { product_id: product.id, ...Object.fromEntries(data) });
            setVariants((current) => [...current, created]);
            setVariantId(created.id);
            setAvailability(null);
          }, "Variante creada.")}>
            <Field label="Nombre de la variante"><input name="name" required maxLength={200} /></Field>
            <Field label="Modalidad"><select name="modality">
              <option value="session">Sesión con cupo</option><option value="exclusive_resource">Recurso exclusivo</option>
            </select></Field>
            <Button type="submit" isDisabled={busy}>Crear variante</Button>
          </form>}
          <form className="grid content-start gap-3" onSubmit={form(async (data) => {
            await api(`${scope}/products/${product.id}/policies`, Object.fromEntries(data));
          }, "Versión de política guardada.")}>
            <Field label="Condiciones de la política">
              <textarea name="terms" className="min-h-24 rounded-lg border border-[#9aafac] p-2" required maxLength={5000} />
            </Field>
            <Button type="submit" isDisabled={busy}>Guardar versión de política</Button>
          </form>
        </>}
        {variants.length > 0 && <div className="lg:col-span-2"><Field label="Variante">
          <select value={variantId} disabled={busy} onChange={(event) => { setVariantId(event.target.value); setAvailability(null); }}>
            {variants.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
          </select>
        </Field></div>}
        {variant && product?.status !== "archived" && <>
          <form className="grid content-start gap-3" onSubmit={form(async (data) => {
            await api(`${scope}/rates`, { variant_id: variant.id, ...Object.fromEntries(data) });
          }, "Versión de tarifa guardada.")}>
            <Field label="Importe decimal"><input name="amount" inputMode="decimal" placeholder="250.00" required /></Field>
            <Field label="Moneda"><input name="currency" maxLength={3} pattern="[A-Z]{3}" defaultValue="MXN" required /></Field>
            <Button type="submit" isDisabled={busy}>Guardar tarifa base</Button>
          </form>
          {variant.modality === "session"
            ? <form className="grid content-start gap-3" onSubmit={form(async (data) => {
                await api(`${scope}/sessions`, { variant_id: variant.id, capacity: Number(data.get("capacity")), ...utcFields(data) });
              }, "Sesión registrada.")}>
                <Field label="Cupo de la sesión"><input name="capacity" type="number" min={1} step={1} required /></Field>
                <Period />
                <Button type="submit" isDisabled={busy}>Registrar sesión</Button>
              </form>
            : resources.length > 0 && <form className="grid content-start gap-3" onSubmit={form(async (data) => {
                const mode = data.get("mode");
                const fields = mode === "windows" ? { ...utcFields(data), variant_id: variant.id } : utcFields(data);
                await api(`${scope}/resources/${data.get("resource")}/${mode}`, fields);
                setAvailability(null);
              }, "Intervalo registrado.")}>
                <Field label="Recurso"><select name="resource">{resources.map(({ id, name }) => <option key={id} value={id}>{name}</option>)}</select></Field>
                <Field label="Tipo de intervalo"><select name="mode">
                  <option value="windows">Horario habilitado para la variante</option><option value="blocks">Bloqueo para todo el recurso</option>
                </select></Field>
                <Period />
                <Button type="submit" isDisabled={busy}>Registrar intervalo</Button>
              </form>}
        </>}
        {variant && product?.status === "published" && <form className="grid content-start gap-3" onSubmit={form(async (data) => {
          const interval = utcFields(data);
          const query = new URLSearchParams({ variant_id: variant.id, from: interval.start_at, to: interval.end_at });
          setAvailability(null);
          setAvailability(await api<Availability>(`${scope}/availability?${query}`));
        })}>
          <Period />
          <Button type="submit" isDisabled={busy}>Consultar disponibilidad</Button>
        </form>}
        {availability && <div className="grid gap-3 rounded-xl bg-[#eef3f2] p-4">
          <h3 className="font-semibold">Disponibilidad · {availability.time_zone}</h3>
          {availability.items.length === 0 && <p>Sin disponibilidad en el intervalo.</p>}
          <ul className="grid gap-2 text-sm">
            {availability.items.map((item) => {
              const format = (time: string) => new Date(time).toLocaleString("es-MX", { timeZone: availability.time_zone });
              const resource = resources.find(({ id }) => id === item.resource_id);
              return <li key={`${item.session_id ?? item.resource_id}-${item.start_at}`}>
                {resource && <span>{resource.name} · </span>}
                {format(item.start_at)} – {format(item.end_at)}
                {item.available_quantity !== undefined && <span> · Cupo {item.available_quantity}</span>}
              </li>;
            })}
          </ul>
        </div>}
        <ReservationPanel key={scope} scope={scope} variantId={variantId} slots={availability?.items ?? []} />
      </div>}
    </section>
  );
}
