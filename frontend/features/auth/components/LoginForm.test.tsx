import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { authApi } from '../api';
import { LoginForm } from './LoginForm';

const push = vi.fn();
vi.mock('next/navigation', () => ({ useRouter: () => ({ push, replace: push }) }));
vi.mock('../api', () => ({ authApi: { login: vi.fn() } }));

function renderizar() {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
  render(<QueryClientProvider client={client}><LoginForm /></QueryClientProvider>);
}

describe('LoginForm', () => {
  beforeEach(() => {
    push.mockClear();
    vi.mocked(authApi.login).mockReset();
  });

  it('valida o e-mail antes de enviar', async () => {
    renderizar();

    await userEvent.type(screen.getByLabelText('E-mail'), 'invalido');
    await userEvent.type(screen.getByLabelText('Senha'), 'x');
    await userEvent.click(screen.getByRole('button', { name: 'Entrar' }));

    expect(await screen.findByText('Informe um e-mail válido.')).toBeInTheDocument();
    expect(screen.getByLabelText('E-mail')).toHaveAccessibleDescription('Informe um e-mail válido.');
    expect(authApi.login).not.toHaveBeenCalled();
  });

  it('com sucesso, vai para o dashboard', async () => {
    vi.mocked(authApi.login).mockResolvedValue({
      data: {
        id: '1', nome: 'Ana', email: 'ana@x.com', papel: 'PROPRIETARIO',
        tenant: { id: 't', razao_social: 'X Ltda', nome_fantasia: null, cnpj: '11222333000181', situacao: 'ATIVA', teste_termina_em: null },
      },
    });
    renderizar();

    await userEvent.type(screen.getByLabelText('E-mail'), 'ana@x.com');
    await userEvent.type(screen.getByLabelText('Senha'), 'Senha123');
    await userEvent.click(screen.getByRole('button', { name: 'Entrar' }));

    expect(authApi.login).toHaveBeenCalledWith({ email: 'ana@x.com', password: 'Senha123' });
    await vi.waitFor(() => expect(push).toHaveBeenCalledWith('/dashboard'));
  });

  it('admin da plataforma vai para /admin', async () => {
    vi.mocked(authApi.login).mockResolvedValue({
      data: { id: '1', nome: 'Admin', email: 'admin@x.com', papel: 'SUPERADMIN', tenant: null },
    });
    renderizar();

    await userEvent.type(screen.getByLabelText('E-mail'), 'admin@x.com');
    await userEvent.type(screen.getByLabelText('Senha'), 'Senha123');
    await userEvent.click(screen.getByRole('button', { name: 'Entrar' }));

    await vi.waitFor(() => expect(push).toHaveBeenCalledWith('/admin'));
  });

  it('mostra o erro da API e não navega', async () => {
    vi.mocked(authApi.login).mockRejectedValue(
      new ApiError(422, 'Dados inválidos.', { email: ['E-mail ou senha incorretos.'] }),
    );
    renderizar();

    await userEvent.type(screen.getByLabelText('E-mail'), 'ana@x.com');
    await userEvent.type(screen.getByLabelText('Senha'), 'errada');
    await userEvent.click(screen.getByRole('button', { name: 'Entrar' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('E-mail ou senha incorretos.');
    expect(push).not.toHaveBeenCalled();
  });

  it('duplo clique envia uma única vez', async () => {
    let resolver: (v: unknown) => void = () => {};
    vi.mocked(authApi.login).mockReturnValue(new Promise((r) => { resolver = r; }) as never);
    renderizar();

    await userEvent.type(screen.getByLabelText('E-mail'), 'ana@x.com');
    await userEvent.type(screen.getByLabelText('Senha'), 'Senha123');
    const botao = screen.getByRole('button', { name: 'Entrar' });
    await act(async () => {
      fireEvent.click(botao);
      fireEvent.click(botao);
    });

    expect(authApi.login).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Entrando...' })).toBeDisabled();
    resolver({ data: { id: '1', nome: 'Ana', email: 'ana@x.com', papel: 'PROPRIETARIO', tenant: null } });
  });
});
