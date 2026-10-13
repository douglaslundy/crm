import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { crmApi } from '../api';
import type { RegistroDaTimeline } from '../types';
import { TimelineDoCliente } from './TimelineDoCliente';

vi.mock('../api', async (original) => {
  const real = await original<typeof import('../api')>();
  return { ...real, crmApi: { ...real.crmApi, timeline: vi.fn(), criarNota: vi.fn(async () => ({ data: {} })) } };
});

function registro(extra: Partial<RegistroDaTimeline>): RegistroDaTimeline {
  return {
    id: 'r1', tipo: 'NOTA', texto: 'Prefere à tarde', dados: {}, autor: { id: 'u1', nome: 'Dono' },
    negocio_id: null, atividade_id: null, ocorreu_em: '2026-10-14T15:00:00Z', ...extra,
  };
}

function renderizar(podeEscrever = true) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}><TimelineDoCliente clienteId="c1" podeEscrever={podeEscrever} /></QueryClientProvider>,
  );
}

describe('TimelineDoCliente', () => {
  beforeEach(() => vi.clearAllMocks());

  it('lista os registros com autor', async () => {
    vi.mocked(crmApi.timeline).mockResolvedValue({
      data: [registro({}), registro({ id: 'r2', tipo: 'NEGOCIO_MOVIDO', texto: 'Negócio movido de A para B: Brindes' })],
      next_cursor: null,
    });
    renderizar();

    expect(await screen.findByText('Prefere à tarde')).toBeInTheDocument();
    expect(screen.getByText('Negócio movido de A para B: Brindes')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /carregar mais/i })).not.toBeInTheDocument();
  });

  it('registra nota e limpa o campo', async () => {
    vi.mocked(crmApi.timeline).mockResolvedValue({ data: [], next_cursor: null });
    renderizar();

    const campo = await screen.findByLabelText('Nova nota');
    await userEvent.type(campo, 'Ligou pedindo desconto');
    await userEvent.click(screen.getByRole('button', { name: /registrar nota/i }));

    expect(crmApi.criarNota).toHaveBeenCalledWith('c1', 'Ligou pedindo desconto');
    await vi.waitFor(() => expect(campo).toHaveValue(''));
  });

  it('carrega mais pelo cursor', async () => {
    vi.mocked(crmApi.timeline)
      .mockResolvedValueOnce({ data: [registro({})], next_cursor: 'CUR' })
      .mockResolvedValueOnce({ data: [registro({ id: 'r9', texto: 'Antiga' })], next_cursor: null });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: /carregar mais/i }));

    expect(await screen.findByText('Antiga')).toBeInTheDocument();
    expect(crmApi.timeline).toHaveBeenLastCalledWith('c1', 'CUR');
  });

  it('sem permissão de escrita não mostra o formulário de nota', async () => {
    vi.mocked(crmApi.timeline).mockResolvedValue({ data: [], next_cursor: null });
    renderizar(false);

    await screen.findByText('Nada registrado para este cliente ainda.');
    expect(screen.queryByLabelText('Nova nota')).not.toBeInTheDocument();
  });
});
