const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000';

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly errors: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** Primeira mensagem de validação, ou a mensagem geral. */
  primeiraMensagem(): string {
    const primeira = Object.values(this.errors)[0]?.[0];
    return primeira ?? this.message;
  }
}

function lerXsrf(): string | undefined {
  const achado = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
  return achado ? decodeURIComponent(achado[1]) : undefined;
}

async function garantirCsrf(): Promise<void> {
  await fetch(`${API_URL}/sanctum/csrf-cookie`, { credentials: 'include' });
}

/** 419 = token CSRF recusado (sessão do servidor perdida). O servidor não reemite o cookie no 419. */
const CSRF_EXPIRADO = 419;
const MENSAGEM_CSRF = 'Sua sessão expirou. Recarregue a página e tente novamente.';

function enviar(path: string, init: RequestInit): Promise<Response> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  if (init.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json');
  const xsrf = lerXsrf();
  if (xsrf) headers.set('X-XSRF-TOKEN', xsrf);

  return fetch(`${API_URL}${path}`, { ...init, headers, credentials: 'include' });
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const metodo = (init.method ?? 'GET').toUpperCase();
  if (metodo !== 'GET' && !lerXsrf()) {
    await garantirCsrf();
  }

  let resposta = await enviar(path, init);
  if (resposta.status === CSRF_EXPIRADO) {
    await garantirCsrf();
    resposta = await enviar(path, init);
  }
  if (resposta.status === 204) return undefined as T;
  if (resposta.status === CSRF_EXPIRADO) throw new ApiError(CSRF_EXPIRADO, MENSAGEM_CSRF);

  const corpo = (await resposta.json().catch(() => ({}))) as {
    message?: string;
    errors?: Record<string, string[]>;
  };
  if (!resposta.ok) {
    throw new ApiError(resposta.status, corpo.message ?? 'Erro inesperado. Tente novamente.', corpo.errors ?? {});
  }
  return corpo as T;
}
