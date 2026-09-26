import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactElement } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { authApi } from '../api';
import { EsqueciSenhaForm } from './EsqueciSenhaForm';
import { RedefinirSenhaForm } from './RedefinirSenhaForm';

vi.mock('next/navigation', () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }) }));
vi.mock('sonner', () => ({ toast: { success: vi.fn() } }));
vi.mock('../api', () => ({ authApi: { esqueciSenha: vi.fn(), redefinirSenha: vi.fn() } }));

function renderizar(ui: ReactElement) {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

/** Dois cliques no mesmo tick, sem esperar o React re-renderizar entre eles. */
async function cliqueDuplo(botao: HTMLElement) {
  await act(async () => {
    fireEvent.click(botao);
    fireEvent.click(botao);
  });
}

const pendente = () => new Promise(() => {}) as never;

describe('formulários de senha', () => {
  beforeEach(() => {
    vi.mocked(authApi.esqueciSenha).mockReset();
    vi.mocked(authApi.redefinirSenha).mockReset();
  });

  it('esqueci a senha: duplo clique envia uma única vez', async () => {
    vi.mocked(authApi.esqueciSenha).mockReturnValue(pendente());
    renderizar(<EsqueciSenhaForm />);

    await userEvent.type(screen.getByLabelText('E-mail cadastrado'), 'ana@x.com');
    await cliqueDuplo(screen.getByRole('button', { name: 'Enviar link' }));

    expect(authApi.esqueciSenha).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Enviando...' })).toBeDisabled();
  });

  it('redefinir senha: duplo clique envia uma única vez', async () => {
    vi.mocked(authApi.redefinirSenha).mockReturnValue(pendente());
    renderizar(<RedefinirSenhaForm token="t" email="ana@x.com" />);

    await userEvent.type(screen.getByLabelText('Nova senha'), 'Senha123');
    await userEvent.type(screen.getByLabelText('Confirmar nova senha'), 'Senha123');
    await cliqueDuplo(screen.getByRole('button', { name: 'Redefinir senha' }));

    expect(authApi.redefinirSenha).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Salvando...' })).toBeDisabled();
  });
});
