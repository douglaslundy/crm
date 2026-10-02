import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { api } from '@/lib/api';
import { ImportarCsvForm } from './ImportarCsvForm';

vi.mock('@/lib/api', async () => {
  const real = await vi.importActual<typeof import('@/lib/api')>('@/lib/api');
  return { ...real, api: vi.fn() };
});

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('ImportarCsvForm', () => {
  it('envia o arquivo e mostra os erros por linha', async () => {
    vi.mocked(api).mockResolvedValue({ data: { criados: 1, atualizados: 0, erros: [{ linha: 3, motivo: 'SKU duplicado.' }] } });
    renderizar(<ImportarCsvForm endpoint="/api/app/produtos/importar" queryKey={['produtos']} />);

    const arquivo = new File(['sku,nome\nA,B'], 'produtos.csv', { type: 'text/csv' });
    await userEvent.upload(screen.getByLabelText('Arquivo CSV'), arquivo);
    await userEvent.click(screen.getByRole('button', { name: 'Importar CSV' }));

    await waitFor(() => expect(api).toHaveBeenCalledWith('/api/app/produtos/importar', expect.objectContaining({ method: 'POST' })));
    expect(await screen.findByText('Linha 3: SKU duplicado.')).toBeInTheDocument();
  });
});
