import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { cadastroApi } from '../api';
import { ListaDePlanos } from './ListaDePlanos';
import { planoDeTeste } from './fixtures';

vi.mock('../api', () => ({ cadastroApi: { planos: vi.fn() } }));

describe('ListaDePlanos', () => {
  it('mostra preço, teste grátis, módulos, limites e o link para começar', async () => {
    vi.mocked(cadastroApi.planos).mockResolvedValue({ data: [planoDeTeste] });
    render(<QueryClientProvider client={new QueryClient()}><ListaDePlanos /></QueryClientProvider>);

    expect(await screen.findByRole('heading', { name: 'Essencial' })).toBeInTheDocument();
    expect(screen.getByText(/99,90/)).toBeInTheDocument();
    expect(screen.getByText('14 dias grátis')).toBeInTheDocument();
    expect(screen.getByText('NF-e')).toBeInTheDocument();
    expect(screen.getByText('Usuários: 3')).toBeInTheDocument();
    expect(screen.getByText('Clientes: ilimitado')).toBeInTheDocument();
    expect(screen.queryByText(/Serviços/)).not.toBeInTheDocument(); // limite 0 não aparece
    expect(screen.getByRole('link', { name: 'Começar' })).toHaveAttribute('href', '/cadastro?plano=p1');
  });
});
