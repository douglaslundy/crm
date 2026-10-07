import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { ApiError } from '@/lib/api';
import { vendasApi } from '../api';
import type { Venda } from '../types';
import { DetalheDaVenda } from './DetalheDaVenda';

const push = vi.fn();

vi.mock('../api', () => ({
  QUERY_KEY_VENDAS: ['vendas'],
  vendasApi: { buscar: vi.fn(), criar: vi.fn(), editar: vi.fn(), excluir: vi.fn(), emitir: vi.fn() },
}));
vi.mock('@/features/notas/api', () => ({ QUERY_KEY_NOTAS: ['notas'] }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ push }) }));
vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: vi.fn() }));

function comPapel(papel: string) {
  vi.mocked(useUsuario).mockReturnValue({ data: { papel } } as never);
}

function venda(statusNfe: 'RASCUNHO' | 'PROCESSANDO' | 'AUTORIZADA' = 'RASCUNHO', statusNfse: 'RASCUNHO' | 'PROCESSANDO' | 'AUTORIZADA' = 'RASCUNHO'): Venda {
  return {
    id: 'v1', cliente: { id: 'c1', nome: 'Maria Teste' }, desconto_centavos: 1600, subtotal_centavos: 16000, total_centavos: 14400,
    notas: [
      { id: 'n-nfe', modelo: 'NFE', status: statusNfe, numero: null, serie: null, subtotal_centavos: 1000, desconto_centavos: 100, total_centavos: 900 },
      { id: 'n-nfse', modelo: 'DPS', status: statusNfse, numero: null, serie: null, subtotal_centavos: 15000, desconto_centavos: 1500, total_centavos: 13500 },
    ],
  };
}

function renderizar() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}><DetalheDaVenda id="v1" /></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  comPapel('ADMIN');
});

describe('DetalheDaVenda', () => {
  it('mostra as duas notas com status, totais e link para cada uma', async () => {
    vi.mocked(vendasApi.buscar).mockResolvedValue({ data: venda() });
    renderizar();

    expect(await screen.findByText(/Maria Teste/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Abrir NF-e/ })).toHaveAttribute('href', '/notas/n-nfe');
    expect(screen.getByRole('link', { name: /Abrir NFS-e/ })).toHaveAttribute('href', '/notas/n-nfse');
    expect(screen.getByText(/R\$\s*9,00/)).toBeInTheDocument();
    expect(screen.getByText(/R\$\s*135,00/)).toBeInTheDocument();
    expect(screen.getAllByText('Rascunho')).toHaveLength(2);
  });

  it('Emitir as duas chama a API sem confirmação e mostra o andamento', async () => {
    vi.mocked(vendasApi.buscar).mockResolvedValue({ data: venda() });
    vi.mocked(vendasApi.emitir).mockResolvedValue({
      data: { id: 'v1', notas: [
        { nota_id: 'n-nfe', modelo: 'NFE', status: 'PROCESSANDO', bloqueio: null },
        { nota_id: 'n-nfse', modelo: 'DPS', status: 'PROCESSANDO', bloqueio: null },
      ] },
    });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: 'Emitir as duas' }));

    await waitFor(() => expect(vendasApi.emitir).toHaveBeenCalledWith('v1', false));
  });

  it('com nota bloqueada lista as mensagens e oferece emitir só a válida', async () => {
    vi.mocked(vendasApi.buscar).mockResolvedValue({ data: venda() });
    vi.mocked(vendasApi.emitir)
      .mockRejectedValueOnce(new ApiError(409, 'Uma das notas da venda está com pendência.', {}, {
        codigo: 'FISCAL_VENDA_NOTA_BLOQUEADA',
        notas: [
          { nota_id: 'n-nfe', modelo: 'NFE', status: 'RASCUNHO', bloqueio: { codigo: 'FISCAL_PRODUTO_INCOMPLETO', mensagem: 'O produto "Filtro" está sem: NCM.' } },
          { nota_id: 'n-nfse', modelo: 'DPS', status: 'RASCUNHO', bloqueio: null },
        ],
      }))
      .mockResolvedValueOnce({ data: { id: 'v1', notas: [] } });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: 'Emitir as duas' }));

    expect(await screen.findByText(/está sem: NCM/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Emitir só a válida' }));
    await waitFor(() => expect(vendasApi.emitir).toHaveBeenLastCalledWith('v1', true));
  });

  it('Excluir venda só aparece com as duas notas em rascunho e chama o DELETE', async () => {
    vi.mocked(vendasApi.buscar).mockResolvedValue({ data: venda() });
    vi.mocked(vendasApi.excluir).mockResolvedValue(undefined);
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: 'Excluir venda' }));
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar exclusão' }));

    await waitFor(() => expect(vendasApi.excluir).toHaveBeenCalledWith('v1'));
    await waitFor(() => expect(push).toHaveBeenCalledWith('/notas'));
  });

  it('com uma nota já emitida não oferece excluir nem editar', async () => {
    vi.mocked(vendasApi.buscar).mockResolvedValue({ data: venda('PROCESSANDO', 'RASCUNHO') });
    renderizar();

    await screen.findByText(/Maria Teste/);
    expect(screen.queryByRole('button', { name: 'Excluir venda' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Editar venda' })).not.toBeInTheDocument();
  });

  it('quem não emite não vê o botão de emitir', async () => {
    comPapel('VENDEDOR');
    vi.mocked(vendasApi.buscar).mockResolvedValue({ data: venda() });
    renderizar();

    await screen.findByText(/Maria Teste/);
    expect(screen.queryByRole('button', { name: 'Emitir as duas' })).not.toBeInTheDocument();
  });
});
