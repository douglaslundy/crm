import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QUERY_KEY_USUARIO } from '@/features/auth/hooks/useUsuario';
import type { Papel } from '@/features/auth/types';
import { ApiError } from '@/lib/api';
import { adminApi } from '../api';
import type { Empresa } from '../types';
import { DetalheDaEmpresa } from './DetalheDaEmpresa';
import { ListaDeEmpresas } from './ListaDeEmpresas';
import { MudarSituacaoForm } from './MudarSituacaoForm';
import { TrocarPlanoForm } from './TrocarPlanoForm';

vi.mock('../api', () => ({
  adminApi: { empresas: vi.fn(), empresa: vi.fn(), planos: vi.fn(), mudarSituacao: vi.fn(), trocarPlano: vi.fn() },
}));

const empresa: Empresa = {
  id: 'e1', cnpj: '11222333000181', razao_social: 'Padaria Pão Bom Ltda', nome_fantasia: 'Pão Bom', situacao: 'ATIVA',
  teste_termina_em: null, situacao_alterada_em: null, plano: { id: 'p1', nome: 'Essencial', preco_mensal_centavos: 9900 },
  criada_em: '2026-09-27T12:00:00+00:00', consumo: [{ recurso: 'USUARIOS', uso: 3, limite: 5 }],
};

function renderizar(ui: ReactNode, papel: Papel = 'SUPERADMIN') {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  client.setQueryData(QUERY_KEY_USUARIO, { id: 'a', nome: 'Admin', email: 'a@x.com', papel, tenant: null });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('admin de empresas', () => {
  beforeEach(() => {
    vi.mocked(adminApi.planos).mockResolvedValue({ data: [] });
    vi.mocked(adminApi.empresas).mockResolvedValue({ data: [empresa], meta: { next_cursor: null, prev_cursor: null, per_page: 20 } });
  });

  it('lista empresas e filtra por situação', async () => {
    renderizar(<ListaDeEmpresas />);

    expect((await screen.findAllByText('Padaria Pão Bom Ltda')).length).toBeGreaterThan(0);
    expect(screen.getAllByText('11.222.333/0001-81').length).toBeGreaterThan(0);

    await userEvent.selectOptions(screen.getByLabelText('Situação'), 'SUSPENSA');
    await waitFor(() =>
      expect(adminApi.empresas).toHaveBeenLastCalledWith({ situacao: 'SUSPENSA', plano_id: '', busca: '' }, undefined),
    );
  });

  it('mudar situação exige motivo e envia a transição', async () => {
    vi.mocked(adminApi.mudarSituacao).mockResolvedValue({ data: { ...empresa, situacao: 'SUSPENSA' } });
    renderizar(<MudarSituacaoForm empresa={empresa} />);

    await userEvent.selectOptions(screen.getByLabelText('Nova situação'), 'SUSPENSA');
    await userEvent.click(screen.getByRole('button', { name: 'Aplicar' }));
    expect(await screen.findByLabelText('Motivo')).toHaveAccessibleDescription('Descreva o motivo (mínimo de 3 caracteres).');
    expect(adminApi.mudarSituacao).not.toHaveBeenCalled();

    await userEvent.type(screen.getByLabelText('Motivo'), 'Inadimplência');
    await userEvent.click(screen.getByRole('button', { name: 'Aplicar' }));
    await waitFor(() => expect(adminApi.mudarSituacao).toHaveBeenCalledWith('e1', { situacao: 'SUSPENSA', motivo: 'Inadimplência' }));
  });

  it('troca de plano com excesso lista o que passou do limite', async () => {
    vi.mocked(adminApi.planos).mockResolvedValue({
      data: [{
        id: 'p2', nome: 'Básico', descricao: null, preco_mensal_centavos: 4900, preco_anual_centavos: null, dias_teste: 0,
        politica_excedente: 'BLOQUEAR', preco_documento_excedente_centavos: null, modulos: [],
        limites: { USUARIOS: 1, CLIENTES: 0, PRODUTOS: 0, SERVICOS: 0, DOCUMENTOS_MES: 0, API_REQUISICOES_MIN: 0 },
        ativo: true, visivel: true, ordem: 0,
      }],
    });
    vi.mocked(adminApi.trocarPlano).mockRejectedValue(
      new ApiError(422, 'O uso atual da empresa passa os limites do plano escolhido.', {}, {
        codigo: 'PLANO_EXCEDIDO', excessos: [{ recurso: 'USUARIOS', uso: 3, limite: 1 }],
      }),
    );
    renderizar(<TrocarPlanoForm empresa={empresa} />);

    await userEvent.selectOptions(await screen.findByLabelText('Novo plano'), 'p2');
    await userEvent.click(screen.getByRole('button', { name: 'Trocar plano' }));

    const alerta = await screen.findByRole('alert');
    expect(alerta).toHaveTextContent('O uso atual da empresa passa os limites do plano escolhido.');
    expect(alerta).toHaveTextContent('Usuários: uso 3, limite 1');
  });

  it('suporte só consulta: não vê as ações', async () => {
    vi.mocked(adminApi.empresa).mockResolvedValue({ data: empresa });
    renderizar(<DetalheDaEmpresa id="e1" />, 'SUPORTE');

    expect(await screen.findByText('Seu perfil de suporte só permite consultar.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Aplicar' })).not.toBeInTheDocument();
    expect(screen.getByText('3 de 5')).toBeInTheDocument();
  });
});
