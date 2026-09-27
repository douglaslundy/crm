import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QUERY_KEY_USUARIO } from '@/features/auth/hooks/useUsuario';
import { ExigirArea } from './ExigirArea';

const replace = vi.fn();
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace }) }));

function renderizar(area: 'empresa' | 'plataforma', tenant: null | { id: string }) {
  const client = new QueryClient();
  client.setQueryData(QUERY_KEY_USUARIO, { id: '1', nome: 'A', email: 'a@x.com', papel: 'SUPERADMIN', tenant });
  render(<QueryClientProvider client={client}><ExigirArea area={area}><p>área</p></ExigirArea></QueryClientProvider>);
}

describe('ExigirArea', () => {
  beforeEach(() => replace.mockClear());

  it('admin da plataforma na área da empresa vai para /admin', () => {
    renderizar('empresa', null);
    expect(replace).toHaveBeenCalledWith('/admin');
    expect(screen.queryByText('área')).not.toBeInTheDocument();
  });

  it('usuário de empresa na área da plataforma vai para /dashboard', () => {
    renderizar('plataforma', { id: 't' });
    expect(replace).toHaveBeenCalledWith('/dashboard');
  });

  it('área certa mostra o conteúdo', () => {
    renderizar('plataforma', null);
    expect(screen.getByText('área')).toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });
});
