import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { authApi } from '../api';
import { RedefinirSenhaForm } from './RedefinirSenhaForm';

vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: vi.fn() }) }));
vi.mock('../api', () => ({ authApi: { redefinirSenha: vi.fn() } }));

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
});
