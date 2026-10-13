import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { crmApi } from '../api';
import type { Atividade } from '../types';
import { AgendaDoCrm } from './AgendaDoCrm';

const papel = vi.hoisted(() => ({ atual: 'ADMIN' }));
vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: () => ({ data: { papel: papel.atual } }) }));
vi.mock('next/link', () => ({
  default: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
}));
vi.mock('../api', async (original) => {
  const real = await original<typeof import('../api')>();
  return {
    ...real,
    crmApi: {
      ...real.crmApi,
      agenda: vi.fn(),
      concluirAtividade: vi.fn(async () => ({ data: {} })),
      reabrirAtividade: vi.fn(),
      criarAtividade: vi.fn(),
    },
  };
});

function atividade(extra: Partial<Atividade>): Atividade {
  return {
    id: 'a1', tipo: 'LIGACAO', titulo: 'Ligar', descricao: null, agendada_para: '2026-10-14T15:00:00Z',
    concluida_em: null, atrasada: false, cliente: { id: 'c1', nome: 'Ana', telefone: null, email: null },
    negocio: null, responsavel: { id: 'u1', nome: 'Dono' }, ...extra,
  };
}

function renderizar() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><AgendaDoCrm /></QueryClientProvider>);
}

describe('AgendaDoCrm', () => {
  beforeEach(() => {
    papel.atual = 'ADMIN';
    vi.clearAllMocks();
  });

  it('separa atrasadas das do período', async () => {
    vi.mocked(crmApi.agenda).mockResolvedValue({
      data: [atividade({ id: 'a1', titulo: 'Atrasada X', atrasada: true }), atividade({ id: 'a2', titulo: 'De hoje Y' })],
    });
    renderizar();

    expect(await screen.findByText('Atrasadas (1)')).toBeInTheDocument();
    expect(screen.getByText(/Atrasada X/)).toBeInTheDocument();
    expect(screen.getByText(/De hoje Y/)).toBeInTheDocument();
  });

  it('conclui a atividade pelo botão', async () => {
    vi.mocked(crmApi.agenda).mockResolvedValue({ data: [atividade({ id: 'a1', titulo: 'Ligar' })] });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: /concluir/i }));

    expect(crmApi.concluirAtividade).toHaveBeenCalledWith('a1');
  });

  it('papel de leitura não vê concluir nem nova atividade', async () => {
    papel.atual = 'LEITURA';
    vi.mocked(crmApi.agenda).mockResolvedValue({ data: [atividade({})] });
    renderizar();

    await screen.findByText(/Ligar/);
    expect(screen.queryByRole('button', { name: /concluir/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /nova atividade/i })).not.toBeInTheDocument();
  });

  it('mostra estado vazio', async () => {
    vi.mocked(crmApi.agenda).mockResolvedValue({ data: [] });
    renderizar();

    expect(await screen.findByText('Nenhuma atividade neste período.')).toBeInTheDocument();
  });
});
