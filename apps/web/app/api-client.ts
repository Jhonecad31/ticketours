export type Page<T> = { items: T[]; next_cursor: string | null };

export class ApiError extends Error {
  constructor(readonly status: number, message: string, readonly correlationId?: string) {
    super(message);
  }
}

export async function api<T>(route: string, body?: object, method: "POST" | "PATCH" = "POST", extraHeaders: Record<string, string> = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: "application/json", ...extraHeaders };
  if (body) {
    const { token } = await api<{ token: string }>("/auth/csrf");
    headers["X-CSRF-TOKEN"] = token;
    headers["Content-Type"] = "application/json";
  }

  const response = await fetch(`/api/v1${route}`, {
    method: body ? method : "GET",
    headers,
    body: body ? JSON.stringify(body) : undefined,
    credentials: "same-origin",
    cache: "no-store",
  });

  if (response.status === 204) return undefined as T;

  const payload = await response.json().catch(() => null);
  if (!response.ok || payload === null) {
    throw new ApiError(
      response.status,
      payload?.message ?? "No se pudo comunicar con la API.",
      payload?.correlation_id,
    );
  }

  return payload as T;
}

export async function list<T>(route: string, cursor?: string | null): Promise<Page<T>> {
  const query = new URLSearchParams();
  if (cursor) query.set("cursor", cursor);
  const suffix = query.size ? `?${query}` : "";
  return api<Page<T>>(`${route}${suffix}`);
}
