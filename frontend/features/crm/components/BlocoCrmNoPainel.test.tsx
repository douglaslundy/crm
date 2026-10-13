import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { crmApi } from '../api';
import { BlocoCrmNoPainel } from './BlocoCrmNoPainel';

vi.mock('../api', async (original) => {
  const real = await original<typeof import('../api')>();
  return { ...real, crmApi: { ...real.crmApi, resumo: vi.fn() } };
});

function renderizar() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><BlocoCrmNoPainel /></QueryClientProvider>);
}

describe('BlocoCrmNoPainel', () => {
  beforeEach(() => vi.clearAllMocks());

  it('mostra hoje, atrasadas e parados com links', async () => {
    vi.mocked(crmApi.resumo).mockResolvedValue({ data: { hoje: 2, atrasadas: 1, parados: 3 } });
    renderizar();

    expect(await screen.findByRole('link', { name: /hoje/i })).toHaveAttribute('href', '/crm/agenda');
    expect(screen.getByRole('link', { name: /atrasadas/i })).toHaveAttribute('href', '/crm/agenda');
    expect(screen.getByRole('link', { name: /parados/i })).toHaveAttribute('href', '/crm/negocios');
    expect(screen.getByRole('link', { name: /hoje/i })).toHaveTextContent('2');
    expect(screen.getByRole('link', { name: /atrasadas/i })).toHaveTextContent('1');
    expect(screen.getByRole('link', { name: /parados/i })).toHaveTextContent('3');
  });

  it('não renderiza nada quando a API falha', async () => {
    vi.mocked(crmApi.resumo).mockRejectedValue(new ApiError(500, 'Erro'));
    const { container } = renderizar();

    await vi.waitFor(() => expect(crmApi.resumo).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
  });
});
