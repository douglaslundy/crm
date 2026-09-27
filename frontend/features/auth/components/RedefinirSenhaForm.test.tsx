import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { authApi } from '../api';
import { RedefinirSenhaForm } from './RedefinirSenhaForm';

vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn() }) }));
vi.mock('../api', () => ({ authApi: { redefinirSenha: vi.fn(), aceitarConvite: vi.fn() } }));

describe('RedefinirSenhaForm', () => {
  it('link com e-mail malformado mostra "link inválido" em vez de falhar calado', async () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <RedefinirSenhaForm token="abc" email="nao-e-email" />
      </QueryClientProvider>,
    );

    await userEvent.type(screen.getByLabelText('Nova senha'), 'Senha123');
    await userEvent.type(screen.getByLabelText('Confirmar nova senha'), 'Senha123');
    await userEvent.click(screen.getByRole('button', { name: 'Redefinir senha' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Link inválido ou expirado.');
    expect(authApi.redefinirSenha).not.toHaveBeenCalled();
  });

  it('erro de senha fica ligado ao campo', async () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <RedefinirSenhaForm token="abc" email="ana@x.com" />
      </QueryClientProvider>,
    );

    await userEvent.type(screen.getByLabelText('Nova senha'), 'curta');
    await userEvent.click(screen.getByRole('button', { name: 'Redefinir senha' }));

    expect(await screen.findByLabelText('Nova senha')).toHaveAccessibleDescription(
      'A senha precisa ter pelo menos 8 caracteres.',
    );
  });

  it('no modo convite, define a senha pelo endpoint de convite', async () => {
    vi.mocked(authApi.aceitarConvite).mockResolvedValue({ message: 'Senha definida. Faça login para entrar.' });
    render(
      <QueryClientProvider client={new QueryClient()}>
        <RedefinirSenhaForm token="abc" email="bia@x.com" modo="convite" />
      </QueryClientProvider>,
    );

    await userEvent.type(screen.getByLabelText('Nova senha'), 'Senha123');
    await userEvent.type(screen.getByLabelText('Confirmar nova senha'), 'Senha123');
    await userEvent.click(screen.getByRole('button', { name: 'Definir senha' }));

    await waitFor(() => expect(authApi.aceitarConvite).toHaveBeenCalledWith({
      token: 'abc', email: 'bia@x.com', password: 'Senha123', password_confirmation: 'Senha123',
    }));
    expect(authApi.redefinirSenha).not.toHaveBeenCalled();
  });

  it('no modo convite, e-mail malformado mostra o aviso de convite inválido com link para "Esqueci minha senha"', async () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <RedefinirSenhaForm token="abc" email="nao-e-email" modo="convite" />
      </QueryClientProvider>,
    );

    await userEvent.type(screen.getByLabelText('Nova senha'), 'Senha123');
    await userEvent.type(screen.getByLabelText('Confirmar nova senha'), 'Senha123');
    await userEvent.click(screen.getByRole('button', { name: 'Definir senha' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Convite inválido ou expirado. Use "Esqueci minha senha" na tela de entrada para definir sua senha.',
    );
    expect(screen.getByRole('link', { name: 'Esqueci minha senha' })).toHaveAttribute('href', '/esqueci-senha');
    expect(authApi.aceitarConvite).not.toHaveBeenCalled();
  });
});
