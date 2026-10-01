import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { servicosApi } from '../api';
import { ListaDeServicos } from './ListaDeServicos';
import { ServicoForm } from './ServicoForm';

vi.mock('../api', async (importOriginal) => {
  const real = await importOriginal<typeof import('../api')>();
  return {
    ...real,
    servicosApi: { listar: vi.fn(), criar: vi.fn(), editar: vi.fn() },
  };
});

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('serviços', () => {
  it('rejeita código LC 116 fora do formato', async () => {
    renderizar(<ServicoForm />);

    await userEvent.type(screen.getByLabelText('Nome'), 'Consultoria');
    await userEvent.type(screen.getByLabelText('Preço (centavos)'), '10000');
    await userEvent.type(screen.getByLabelText('Código LC 116'), '1401');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar serviço' }));

    expect(await screen.findByText('Use o formato 00.00.')).toBeInTheDocument();
    expect(servicosApi.criar).not.toHaveBeenCalled();
  });

  it('cria serviço completo', async () => {
    vi.mocked(servicosApi.criar).mockResolvedValue({
      data: { id: '1', nome: 'Consultoria', preco_centavos: 10000, codigo_lc116: '14.01', c_trib_nac: '140101', codigo_municipal: null, aliquota_iss: '5.00', nbs: null, pendente_fiscal: false },
    });
    renderizar(<ServicoForm />);

    await userEvent.type(screen.getByLabelText('Nome'), 'Consultoria');
    await userEvent.type(screen.getByLabelText('Preço (centavos)'), '10000');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar serviço' }));

    await waitFor(() => expect(servicosApi.criar).toHaveBeenCalled());
  });

  it('lista serviços pendentes', async () => {
    vi.mocked(servicosApi.listar).mockResolvedValue({
      data: [{ id: '1', nome: 'Consultoria', preco_centavos: 10000, codigo_lc116: null, c_trib_nac: null, codigo_municipal: null, aliquota_iss: null, nbs: null, pendente_fiscal: true }],
    });
    renderizar(<ListaDeServicos />);

    expect(await screen.findByText('Consultoria')).toBeInTheDocument();
    expect(screen.getByText(/pendente/i)).toBeInTheDocument();
  });
});
