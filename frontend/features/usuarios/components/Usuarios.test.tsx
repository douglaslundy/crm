import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Usuario } from '@/features/auth/types';
import { ApiError } from '@/lib/api';
import { usuariosApi } from '../api';
import { ConviteForm } from './ConviteForm';
import { ListaDeUsuarios } from './ListaDeUsuarios';

vi.mock('../api', () => ({
  QUERY_KEY_USUARIOS: ['usuarios'],
  usuariosApi: { listar: vi.fn(), convidar: vi.fn(), editar: vi.fn(), desativar: vi.fn(), reativar: vi.fn() },
}));

const autor: Usuario = {
  id: 'a', nome: 'Admin', email: 'admin@x.com', papel: 'ADMIN',
  tenant: { id: 't', razao_social: 'X', nome_fantasia: null, cnpj: '11222333000181', situacao: 'ATIVA', teste_termina_em: null },
};

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('usuários da empresa', () => {
  beforeEach(() => {
    vi.mocked(usuariosApi.listar).mockResolvedValue({
      data: [
        { id: 'd', nome: 'Dona', email: 'dona@x.com', papel: 'PROPRIETARIO', ativo: true },
        { id: 'a', nome: 'Admin', email: 'admin@x.com', papel: 'ADMIN', ativo: true },
        { id: 'b', nome: 'Bia', email: 'bia@x.com', papel: 'VENDEDOR', ativo: true },
      ],
    });
  });

  it('proprietário sem ações; o próprio usuário não se desativa; os demais sim', async () => {
    vi.mocked(usuariosApi.desativar).mockResolvedValue({ data: { id: 'b', nome: 'Bia', email: 'bia@x.com', papel: 'VENDEDOR', ativo: false } });
    renderizar(<ListaDeUsuarios autor={autor} />);

    const dona = (await screen.findByText('Dona')).closest('li');
    expect(dona).not.toBeNull();
    expect(within(dona as HTMLElement).queryByRole('button')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Desativar Admin' })).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Desativar Bia' }));
    await waitFor(() => expect(usuariosApi.desativar).toHaveBeenCalledWith('b'));
  });

  it('admin não vê a opção de proprietário no convite', () => {
    renderizar(<ConviteForm autor="ADMIN" />);

    const opcoes = within(screen.getByLabelText('Papel')).getAllByRole('option').map((o) => o.textContent);
    expect(opcoes).not.toContain('Proprietário');
    expect(opcoes).toContain('Fiscal');
  });

  it('convite enviado limpa o formulário', async () => {
    vi.mocked(usuariosApi.convidar).mockResolvedValue({ data: { id: 'n', nome: 'Caio', email: 'caio@x.com', papel: 'FISCAL', ativo: true } });
    renderizar(<ConviteForm autor="ADMIN" />);

    await userEvent.type(screen.getByLabelText('Nome'), 'Caio');
    await userEvent.type(screen.getByLabelText('E-mail'), 'caio@x.com');
    await userEvent.selectOptions(screen.getByLabelText('Papel'), 'FISCAL');
    await userEvent.click(screen.getByRole('button', { name: 'Enviar convite' }));

    await waitFor(() => expect(usuariosApi.convidar).toHaveBeenCalledWith({ nome: 'Caio', email: 'caio@x.com', papel: 'FISCAL' }));
    await waitFor(() => expect(screen.getByLabelText('Nome')).toHaveValue(''));
  });

  it('limite do plano aparece como alerta', async () => {
    vi.mocked(usuariosApi.convidar).mockRejectedValue(
      new ApiError(422, 'Seu plano permite até 3 usuários ativos.', {}, { codigo: 'LIMITE_DO_PLANO' }),
    );
    renderizar(<ConviteForm autor="ADMIN" />);

    await userEvent.type(screen.getByLabelText('Nome'), 'Caio');
    await userEvent.type(screen.getByLabelText('E-mail'), 'caio@x.com');
    await userEvent.click(screen.getByRole('button', { name: 'Enviar convite' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Seu plano permite até 3 usuários ativos.');
  });
});
