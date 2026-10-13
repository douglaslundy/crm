import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { crmApi } from '../api';
import type { Funil } from '../types';
import { ConfiguracoesDoCrm } from './ConfiguracoesDoCrm';

vi.mock('../api', () => ({
  QUERY_KEY_CRM: ['crm'],
  crmApi: {
    funis: vi.fn(), motivos: vi.fn(), configuracao: vi.fn(), criarFunil: vi.fn(), editarFunil: vi.fn(),
    criarEtapa: vi.fn(), editarEtapa: vi.fn(), ordenarEtapas: vi.fn(), criarMotivo: vi.fn(), editarMotivo: vi.fn(),
    salvarConfiguracao: vi.fn(),
  },
}));

const papel = vi.hoisted(() => ({ atual: 'ADMIN' }));
vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: () => ({ data: { papel: papel.atual } }) }));

const funil: Funil = {
  id: 'f1', nome: 'Vendas', padrao: true, ativo: true, posicao: 0,
  etapas: [
    { id: 'e1', funil_id: 'f1', nome: 'Qualificação', posicao: 0, probabilidade: 10, ativa: true, dias_para_parado: null },
    { id: 'e2', funil_id: 'f1', nome: 'Proposta', posicao: 1, probabilidade: 40, ativa: true, dias_para_parado: null },
  ],
};

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  papel.atual = 'ADMIN';
  vi.mocked(crmApi.funis).mockResolvedValue({ data: [funil] });
  vi.mocked(crmApi.motivos).mockResolvedValue({ data: [{ id: 'm1', nome: 'Preço', ativo: true }] });
  vi.mocked(crmApi.configuracao).mockResolvedValue({ data: { vendedor_ve_so_os_seus: true } });
});

describe('ConfiguracoesDoCrm', () => {
  it('reordena etapas com o botão descer', async () => {
    vi.mocked(crmApi.ordenarEtapas).mockResolvedValue({ data: funil });
    renderizar(<ConfiguracoesDoCrm />);

    await userEvent.click(await screen.findByRole('button', { name: 'Descer Qualificação' }));

    await waitFor(() => expect(crmApi.ordenarEtapas).toHaveBeenCalledWith('f1', ['e2', 'e1']));
  });

  it('define os dias para parado da etapa', async () => {
    vi.mocked(crmApi.editarEtapa).mockResolvedValue({ data: funil.etapas[0] });
    renderizar(<ConfiguracoesDoCrm />);

    await userEvent.type(await screen.findByLabelText('Dias para parado de Qualificação'), '7');
    await userEvent.tab();

    await waitFor(() => expect(crmApi.editarEtapa).toHaveBeenCalledWith('e1', { dias_para_parado: 7 }));
  });

  it('campo vazio remove o alerta (envia null)', async () => {
    const comAlerta: Funil = {
      ...funil,
      etapas: [{ ...funil.etapas[0], dias_para_parado: 5 }, funil.etapas[1]],
    };
    vi.mocked(crmApi.funis).mockResolvedValue({ data: [comAlerta] });
    vi.mocked(crmApi.editarEtapa).mockResolvedValue({ data: comAlerta.etapas[0] });
    renderizar(<ConfiguracoesDoCrm />);

    await userEvent.clear(await screen.findByLabelText('Dias para parado de Qualificação'));
    await userEvent.tab();

    await waitFor(() => expect(crmApi.editarEtapa).toHaveBeenCalledWith('e1', { dias_para_parado: null }));
  });

  it('cria motivo de perda', async () => {
    vi.mocked(crmApi.criarMotivo).mockResolvedValue({ data: { id: 'm2', nome: 'Prazo', ativo: true } });
    renderizar(<ConfiguracoesDoCrm />);

    await userEvent.type(await screen.findByLabelText('Novo motivo'), 'Prazo');
    await userEvent.click(screen.getByRole('button', { name: 'Adicionar motivo' }));

    await waitFor(() => expect(crmApi.criarMotivo).toHaveBeenCalledWith('Prazo'));
  });

  it('altera o interruptor de visibilidade', async () => {
    vi.mocked(crmApi.salvarConfiguracao).mockResolvedValue({ data: { vendedor_ve_so_os_seus: false } });
    renderizar(<ConfiguracoesDoCrm />);

    await userEvent.click(await screen.findByLabelText('Vendedor vê só os próprios negócios'));

    await waitFor(() => expect(crmApi.salvarConfiguracao).toHaveBeenCalledWith(false));
  });

  it('vendedor vê a configuração sem controles de edição', async () => {
    papel.atual = 'VENDEDOR';
    renderizar(<ConfiguracoesDoCrm />);

    expect(await screen.findByText('Qualificação')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Adicionar motivo' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Descer Qualificação' })).not.toBeInTheDocument();
  });
});
