import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { pendenciasFiscaisApi } from '../api';
import { PendenciasFiscais } from './PendenciasFiscais';

vi.mock('../api', () => ({
  QUERY_KEY_PENDENCIAS_FISCAIS: ['pendencias-fiscais'],
  pendenciasFiscaisApi: { listar: vi.fn(), marcarRevisado: vi.fn() },
}));

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('pendências fiscais', () => {
  it('lista produtos e serviços pendentes e marca como revisado', async () => {
    vi.mocked(pendenciasFiscaisApi.listar).mockResolvedValue({
      data: {
        produtos: [{ id: '1', sku: 'SKU-1', nome: 'Parafuso', unidade: 'UN', preco_centavos: 100, gtin: null, ncm: '12345678', cest: null, origem: 0, tributacao_icms: 'NORMAL', fiscal_fonte: 'PADRAO', fiscal_revisado_em: null, pendente_fiscal: true }],
        servicos: [{ id: '2', nome: 'Consultoria', preco_centavos: 1000, codigo_lc116: null, c_trib_nac: null, codigo_municipal: null, aliquota_iss: null, nbs: null, pendente_fiscal: true }],
      },
    });
    vi.mocked(pendenciasFiscaisApi.marcarRevisado).mockResolvedValue({ data: { id: '1' } as never });
    renderizar(<PendenciasFiscais podeRevisar />);

    expect(await screen.findByText('Parafuso')).toBeInTheDocument();
    expect(screen.getByText('Consultoria')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Marcar Parafuso como revisado' }));
    await waitFor(() => expect(pendenciasFiscaisApi.marcarRevisado).toHaveBeenCalledWith('1'));
  });

  it('sem pendências mostra mensagem positiva', async () => {
    vi.mocked(pendenciasFiscaisApi.listar).mockResolvedValue({ data: { produtos: [], servicos: [] } });
    renderizar(<PendenciasFiscais podeRevisar={false} />);

    expect(await screen.findByText(/nenhuma pendência/i)).toBeInTheDocument();
  });
});
