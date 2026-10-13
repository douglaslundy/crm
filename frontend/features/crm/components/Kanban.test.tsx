import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { crmApi } from '../api';
import type { Funil, Negocio } from '../types';
import { QuadroDeNegocios } from './QuadroDeNegocios';

vi.mock('../api', () => ({
  QUERY_KEY_CRM: ['crm'],
  crmApi: { funis: vi.fn(), negocios: vi.fn(), mover: vi.fn(), criarNegocio: vi.fn() },
}));
vi.mock('@/features/clientes/api', () => ({
  QUERY_KEY_CLIENTES: ['clientes'],
  clientesApi: { listar: vi.fn().mockResolvedValue({ data: [] }) },
}));
vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: () => ({ data: { papel: 'VENDEDOR' } }) }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ push: vi.fn() }) }));

const funil: Funil = {
  id: 'f1', nome: 'Vendas', padrao: true, ativo: true, posicao: 0,
  etapas: [
    { id: 'e1', funil_id: 'f1', nome: 'Qualificação', posicao: 0, probabilidade: 10, ativa: true },
    { id: 'e2', funil_id: 'f1', nome: 'Proposta', posicao: 1, probabilidade: 40, ativa: true },
  ],
};

function negocio(extra: Partial<Negocio> = {}): Negocio {
  return {
    id: 'n1', titulo: 'Reforma', cliente: { id: 'c1', nome: 'Maria', telefone: null, email: null, estagio: 'LEAD' },
    funil_id: 'f1', etapa_id: 'e1', responsavel: { id: 'u1', nome: 'João' }, status: 'ABERTO', valor_centavos: 150000,
    previsao_fechamento: null, posicao_na_etapa: 0, motivo_de_perda_id: null, observacao_da_perda: null,
    fechado_em: null, itens: [], ...extra,
  };
}

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(crmApi.funis).mockResolvedValue({ data: [funil] });
  vi.mocked(crmApi.negocios).mockResolvedValue({ data: [negocio()] });
});

describe('kanban', () => {
  it('mostra uma coluna por etapa com a soma do valor', async () => {
    renderizar(<QuadroDeNegocios />);

    const coluna = await screen.findByRole('region', { name: 'Qualificação' });
    expect(within(coluna).getByText('Reforma')).toBeInTheDocument();
    expect(within(coluna).getAllByText(/R\$\s*1\.500,00/).length).toBeGreaterThan(0);
    expect(screen.getByRole('region', { name: 'Proposta' })).toBeInTheDocument();
  });

  it('move pelo menu "Mover para" e chama a API', async () => {
    vi.mocked(crmApi.mover).mockResolvedValue({ data: negocio({ etapa_id: 'e2' }) });
    // Primeira carga na etapa de origem; depois do movimento o servidor já devolve a nova etapa (refetch).
    vi.mocked(crmApi.negocios)
      .mockResolvedValueOnce({ data: [negocio()] })
      .mockResolvedValue({ data: [negocio({ etapa_id: 'e2' })] });
    renderizar(<QuadroDeNegocios />);

    await userEvent.selectOptions(await screen.findByLabelText('Mover Reforma para'), 'e2');

    await waitFor(() => expect(crmApi.mover).toHaveBeenCalledWith('n1', 'e2', 0));
    const proposta = screen.getByRole('region', { name: 'Proposta' });
    await waitFor(() => expect(within(proposta).getByText('Reforma')).toBeInTheDocument());
  });

  it('volta o cartão e avisa quando a API recusa (422)', async () => {
    vi.mocked(crmApi.mover).mockRejectedValue(new ApiError(422, 'Etapa inválida.', {}));
    renderizar(<QuadroDeNegocios />);

    await userEvent.selectOptions(await screen.findByLabelText('Mover Reforma para'), 'e2');

    await waitFor(() => expect(crmApi.mover).toHaveBeenCalled());
    const origem = screen.getByRole('region', { name: 'Qualificação' });
    await waitFor(() => expect(within(origem).getByText('Reforma')).toBeInTheDocument());
  });

  it('aba Ganhos pede os negócios ganhos', async () => {
    renderizar(<QuadroDeNegocios />);

    await userEvent.click(await screen.findByRole('tab', { name: 'Ganhos' }));

    await waitFor(() => expect(crmApi.negocios).toHaveBeenCalledWith('f1', 'GANHO'));
  });

  it('novo negócio cria lead na hora', async () => {
    vi.mocked(crmApi.criarNegocio).mockResolvedValue({ data: negocio({ id: 'n2' }) });
    renderizar(<QuadroDeNegocios />);

    await userEvent.click(await screen.findByRole('button', { name: 'Novo negócio' }));
    await userEvent.type(screen.getByLabelText('Título'), 'Brindes');
    await userEvent.type(screen.getByLabelText('Nome do lead'), 'Ana');
    await userEvent.type(screen.getByLabelText('Telefone do lead'), '11999990000');
    await userEvent.click(screen.getByRole('button', { name: 'Criar negócio' }));

    await waitFor(() =>
      expect(crmApi.criarNegocio).toHaveBeenCalledWith(
        expect.objectContaining({ titulo: 'Brindes', funil_id: 'f1', etapa_id: 'e1', novo_lead: { nome: 'Ana', telefone: '11999990000' } }),
      ),
    );
  });
});
