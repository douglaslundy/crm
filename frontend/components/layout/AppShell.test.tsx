import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { QUERY_KEY_USUARIO } from '@/features/auth/hooks/useUsuario';
import { AppShell } from './AppShell';

vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn() }), usePathname: () => '/dashboard' }));
vi.mock('next-themes', () => ({ useTheme: () => ({ resolvedTheme: 'light', setTheme: vi.fn() }) }));

describe('AppShell', () => {
  it('renderiza navegação lateral, navegação inferior, usuário e conteúdo', () => {
    const client = new QueryClient();
    client.setQueryData(QUERY_KEY_USUARIO, {
      id: '1', nome: 'Ana Souza', email: 'ana@x.com', papel: 'PROPRIETARIO',
      tenant: { id: 't', nome: 'Empresa X', cnpj: '11222333000181' },
    });

    render(
      <QueryClientProvider client={client}>
        <AppShell><p>página</p></AppShell>
      </QueryClientProvider>,
    );

    const lateral = screen.getByRole('navigation', { name: 'Navegação principal' });
    const inferior = screen.getByRole('navigation', { name: 'Navegação inferior' });
    expect(within(lateral).getByRole('link', { name: /Início/ })).toHaveAttribute('aria-current', 'page');
    expect(within(inferior).getByRole('link', { name: /Início/ })).toBeInTheDocument();
    expect(screen.getByText('Empresa X')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Sair' })).toBeInTheDocument();
    expect(screen.getByText('página')).toBeInTheDocument();
  });
});
