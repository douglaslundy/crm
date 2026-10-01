import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { categoriasFiscaisApi, produtosApi } from '../api';
import { ListaDeProdutos } from './ListaDeProdutos';
import { ProdutoForm } from './ProdutoForm';

vi.mock('../api', async (importOriginal) => {
  const real = await importOriginal<typeof import('../api')>();
  return {
    ...real,
    produtosApi: { listar: vi.fn(), criar: vi.fn(), editar: vi.fn(), aplicarCategoria: vi.fn() },
    categoriasFiscaisApi: { listar: vi.fn(), criar: vi.fn() },
  };
});

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('produtos', () => {
  beforeEach(() => {
    vi.mocked(categoriasFiscaisApi.listar).mockResolvedValue({ data: [] });
  });

  it('cria produto com origem zero preservada', async () => {
    vi.mocked(produtosApi.criar).mockResolvedValue({
      data: { id: '1', sku: 'SKU-1', nome: 'Parafuso', unidade: 'UN', preco_centavos: 500, gtin: null, ncm: null, cest: null, origem: 0, tributacao_icms: null, fiscal_fonte: 'MANUAL', fiscal_revisado_em: null, pendente_fiscal: false },
    });
    renderizar(<ProdutoForm />);

    await userEvent.type(screen.getByLabelText('SKU'), 'SKU-1');
    await userEvent.type(screen.getByLabelText('Nome'), 'Parafuso');
    await userEvent.type(screen.getByLabelText('Unidade'), 'UN');
    await userEvent.type(screen.getByLabelText('Preço (centavos)'), '500');
    await userEvent.type(screen.getByLabelText('Origem'), '0');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar produto' }));

    await waitFor(() => expect(produtosApi.criar).toHaveBeenCalledWith(expect.objectContaining({ origem: 0 })));
  });

  it('limite do plano aparece como alerta', async () => {
    vi.mocked(produtosApi.criar).mockRejectedValue(
      new ApiError(422, 'Seu plano permite até 2 produtos.', {}, { codigo: 'LIMITE_DO_PLANO' }),
    );
    renderizar(<ProdutoForm />);

    await userEvent.type(screen.getByLabelText('SKU'), 'SKU-1');
    await userEvent.type(screen.getByLabelText('Nome'), 'Parafuso');
    await userEvent.type(screen.getByLabelText('Unidade'), 'UN');
    await userEvent.type(screen.getByLabelText('Preço (centavos)'), '500');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar produto' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Seu plano permite até 2 produtos.');
  });

  it('lista produtos e mostra a pendência fiscal', async () => {
    vi.mocked(produtosApi.listar).mockResolvedValue({
      data: [{ id: '1', sku: 'SKU-1', nome: 'Parafuso', unidade: 'UN', preco_centavos: 500, gtin: null, ncm: null, cest: null, origem: null, tributacao_icms: null, fiscal_fonte: 'MANUAL', fiscal_revisado_em: null, pendente_fiscal: true }],
    });
    renderizar(<ListaDeProdutos />);

    expect(await screen.findByText('Parafuso')).toBeInTheDocument();
    expect(screen.getByText(/pendente/i)).toBeInTheDocument();
  });
});
