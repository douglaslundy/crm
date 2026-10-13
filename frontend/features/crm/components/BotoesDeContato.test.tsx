import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { crmApi } from '../api';
import { BotoesDeContato } from './BotoesDeContato';

const erroToast = vi.hoisted(() => vi.fn());
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: (m: string) => erroToast(m) } }));
vi.mock('../api', async (original) => {
  const real = await original<typeof import('../api')>();
  return { ...real, crmApi: { ...real.crmApi, registrarContato: vi.fn() } };
});

function renderizar(podeEscrever: boolean) {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={client}><BotoesDeContato clienteId="c1" podeEscrever={podeEscrever} /></QueryClientProvider>,
  );
}

describe('BotoesDeContato', () => {
  beforeEach(() => vi.clearAllMocks());

  it('registra o contato e abre o canal', async () => {
    const abrir = vi.fn();
    vi.stubGlobal('open', abrir);
    vi.mocked(crmApi.registrarContato).mockResolvedValue({ data: { url: 'https://wa.me/5511999990000', atividade: {} as never } });
    renderizar(true);

    await userEvent.click(screen.getByRole('button', { name: /whatsapp/i }));

    expect(crmApi.registrarContato).toHaveBeenCalledWith('WHATSAPP', { cliente_id: 'c1', negocio_id: undefined });
    await vi.waitFor(() => expect(abrir).toHaveBeenCalledWith('https://wa.me/5511999990000', '_blank', 'noopener,noreferrer'));
  });

  it('mostra a mensagem do servidor quando o cadastro está incompleto e não abre o canal', async () => {
    const abrir = vi.fn();
    vi.stubGlobal('open', abrir);
    vi.mocked(crmApi.registrarContato).mockRejectedValue(
      new ApiError(422, 'Cadastre o telefone do cliente com DDD (10 ou 11 dígitos) para abrir o WhatsApp.'),
    );
    renderizar(true);

    await userEvent.click(screen.getByRole('button', { name: /whatsapp/i }));

    await vi.waitFor(() => expect(erroToast).toHaveBeenCalledWith(expect.stringContaining('DDD')));
    expect(abrir).not.toHaveBeenCalled();
  });

  it('sem permissão de escrita não renderiza os botões', () => {
    renderizar(false);

    expect(screen.queryByRole('button', { name: /whatsapp/i })).not.toBeInTheDocument();
  });
});
