import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { authApi } from '@/features/auth/api';
import { ApiError } from '@/lib/api';
import { AuthGuard } from './AuthGuard';

const replace = vi.fn();
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace, push: replace }) }));
vi.mock('@/features/auth/api', () => ({ authApi: { me: vi.fn() } }));

function renderizar() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <AuthGuard><p>conteúdo protegido</p></AuthGuard>
    </QueryClientProvider>,
  );
}

describe('AuthGuard', () => {
  beforeEach(() => replace.mockClear());

  it('sessão expirada (401) redireciona para o login', async () => {
    vi.mocked(authApi.me).mockRejectedValue(new ApiError(401, 'Unauthenticated.'));
    renderizar();

    await vi.waitFor(() => expect(replace).toHaveBeenCalledWith('/login'));
    expect(screen.queryByText('conteúdo protegido')).not.toBeInTheDocument();
  });

  it('com sessão válida, mostra o conteúdo', async () => {
    vi.mocked(authApi.me).mockResolvedValue({
      data: { id: '1', nome: 'Ana', email: 'ana@x.com', papel: 'PROPRIETARIO', tenant: null },
    });
    renderizar();

    expect(await screen.findByText('conteúdo protegido')).toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });

  it('erro que não é de sessão mostra mensagem e permite tentar de novo', async () => {
    vi.mocked(authApi.me).mockRejectedValue(new ApiError(500, 'Erro interno.'));
    renderizar();

    expect(await screen.findByText('Não foi possível carregar sua sessão.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Tentar novamente' })).toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });
});
