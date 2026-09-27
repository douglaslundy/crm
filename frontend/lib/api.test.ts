import { afterEach, describe, expect, it, vi } from 'vitest';
import { api, ApiError } from './api';

function resposta(status: number, corpo: unknown): Response {
  return new Response(JSON.stringify(corpo), { status, headers: { 'Content-Type': 'application/json' } });
}

describe('api', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  });

  it('envia cookies e o token XSRF e devolve o JSON', async () => {
    document.cookie = 'XSRF-TOKEN=abc%3D';
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(resposta(200, { ok: true }));

    const corpo = await api<{ ok: boolean }>('/api/app/x', { method: 'POST', body: '{}' });

    expect(corpo).toEqual({ ok: true });
    const [, init] = fetchMock.mock.calls[0];
    expect(init?.credentials).toBe('include');
    expect(new Headers(init?.headers).get('X-XSRF-TOKEN')).toBe('abc=');
  });

  it('busca o cookie CSRF antes do primeiro POST quando ele não existe', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(resposta(200, {}));

    await api('/api/app/x', { method: 'POST', body: '{}' });

    expect(String(fetchMock.mock.calls[0][0])).toContain('/sanctum/csrf-cookie');
  });

  it('com token CSRF vencido (419), renova o cookie e repete a requisição uma vez', async () => {
    document.cookie = 'XSRF-TOKEN=velho';
    const fetchMock = vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(resposta(419, { message: 'CSRF token mismatch.' }))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(resposta(200, { ok: true }));

    const corpo = await api<{ ok: boolean }>('/api/app/auth/login', { method: 'POST', body: '{}' });

    expect(corpo).toEqual({ ok: true });
    expect(fetchMock).toHaveBeenCalledTimes(3);
    expect(String(fetchMock.mock.calls[1][0])).toContain('/sanctum/csrf-cookie');
  });

  it('se o 419 persistir, devolve mensagem em português', async () => {
    document.cookie = 'XSRF-TOKEN=velho';
    vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(resposta(419, { message: 'CSRF token mismatch.' }))
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(resposta(419, { message: 'CSRF token mismatch.' }));

    const erro = await api('/api/app/auth/login', { method: 'POST', body: '{}' }).catch((e: unknown) => e);

    expect((erro as ApiError).status).toBe(419);
    expect((erro as ApiError).primeiraMensagem()).toBe('Sua sessão expirou. Recarregue a página e tente novamente.');
  });

  it('converte erro HTTP em ApiError com status, mensagem e erros', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      resposta(422, { message: 'Dados inválidos.', errors: { email: ['E-mail ou senha incorretos.'] } }),
    );

    const erro = await api('/api/app/auth/me').catch((e: unknown) => e);

    expect(erro).toBeInstanceOf(ApiError);
    expect((erro as ApiError).status).toBe(422);
    expect((erro as ApiError).errors.email[0]).toBe('E-mail ou senha incorretos.');
  });

  it('expõe o código e o corpo dos erros de negócio', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      resposta(422, { message: 'Excedido.', codigo: 'PLANO_EXCEDIDO', excessos: [{ recurso: 'USUARIOS', uso: 3, limite: 1 }] }),
    );

    const erro = await api('/api/admin/x').catch((e: unknown) => e);

    expect(erro).toBeInstanceOf(ApiError);
    expect((erro as ApiError).codigo).toBe('PLANO_EXCEDIDO');
    expect((erro as ApiError).corpo.excessos).toEqual([{ recurso: 'USUARIOS', uso: 3, limite: 1 }]);
  });
});
