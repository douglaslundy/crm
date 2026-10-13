import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { crmApi } from '../api';
import type { Negocio } from '../types';
import { DetalheDoNegocio } from './DetalheDoNegocio';

vi.mock('../api', () => ({
  QUERY_KEY_CRM: ['crm'],
  crmApi: { negocio: vi.fn(), motivos: vi.fn(), ganhar: vi.fn(), perder: vi.fn(), definirItens: vi.fn(), editarNegocio: vi.fn() },
}));
vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: () => ({ data: { papel: 'VENDEDOR' } }) }));
vi.mock('@/features/produtos/api', () => ({ QUERY_KEY_PRODUTOS: ['produtos'], produtosApi: { listar: vi.fn().mockResolvedValue({ data: [] }) } }));
vi.mock('@/features/servicos/api', () => ({ QUERY_KEY_SERVICOS: ['servicos'], servicosApi: { listar: vi.fn().mockResolvedValue({ data: [] }) } }));

function negocio(extra: Partial<Negocio> = {}): Negocio {
  return {
    id: 'n1', titulo: 'Reforma', cliente: { id: 'c1', nome: 'Maria', telefone: null, email: null, estagio: 'LEAD' },
    funil_id: 'f1', etapa_id: 'e1', responsavel: { id: 'u1', nome: 'João' }, status: 'ABERTO', valor_centavos: 5000,
    previsao_fechamento: null, posicao_na_etapa: 0, motivo_de_perda_id: null, observacao_da_perda: null, fechado_em: null,
    itens: [{ id: 'i1', tipo: 'PRODUTO', item_id: 'p1', descricao: 'Caneca', quantidade: 2, valor_unitario_centavos: 2500 }],
    ...extra,
  };
}

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(crmApi.negocio).mockResolvedValue({ data: negocio() });
  vi.mocked(crmApi.motivos).mockResolvedValue({ data: [{ id: 'm1', nome: 'Preço', ativo: true }, { id: 'm2', nome: 'Antigo', ativo: false }] });
});

describe('DetalheDoNegocio', () => {
  it('mostra itens e valor do negócio', async () => {
    renderizar(<DetalheDoNegocio id="n1" />);

    expect(await screen.findByText('Caneca')).toBeInTheDocument();
    expect(screen.getAllByText(/R\$\s*50,00/).length).toBeGreaterThan(0);
  });

  it('ganha o negócio', async () => {
    vi.mocked(crmApi.ganhar).mockResolvedValue({ data: negocio({ status: 'GANHO' }) });
    renderizar(<DetalheDoNegocio id="n1" />);

    await userEvent.click(await screen.findByRole('button', { name: 'Ganhar' }));

    await waitFor(() => expect(crmApi.ganhar).toHaveBeenCalledWith('n1'));
  });

  it('perder exige motivo e só oferece motivos ativos', async () => {
    vi.mocked(crmApi.perder).mockResolvedValue({ data: negocio({ status: 'PERDIDO' }) });
    renderizar(<DetalheDoNegocio id="n1" />);

    await userEvent.click(await screen.findByRole('button', { name: 'Perder' }));
    expect(screen.queryByRole('option', { name: 'Antigo' })).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Confirmar perda' }));
    expect(await screen.findByText('Escolha o motivo da perda.')).toBeInTheDocument();
    expect(crmApi.perder).not.toHaveBeenCalled();

    await userEvent.selectOptions(screen.getByLabelText('Motivo da perda'), 'm1');
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar perda' }));

    await waitFor(() => expect(crmApi.perder).toHaveBeenCalledWith('n1', 'm1', undefined));
  });

  it('negócio fechado não mostra ações', async () => {
    vi.mocked(crmApi.negocio).mockResolvedValue({ data: negocio({ status: 'GANHO' }) });
    renderizar(<DetalheDoNegocio id="n1" />);

    expect(await screen.findByText('Ganho')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Ganhar' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Perder' })).not.toBeInTheDocument();
  });

  it('remover um item reenvia a lista sem ele', async () => {
    vi.mocked(crmApi.definirItens).mockResolvedValue({ data: negocio({ itens: [], valor_centavos: 5000 }) });
    renderizar(<DetalheDoNegocio id="n1" />);

    await userEvent.click(await screen.findByRole('button', { name: 'Remover Caneca' }));

    await waitFor(() => expect(crmApi.definirItens).toHaveBeenCalledWith('n1', []));
  });
});
