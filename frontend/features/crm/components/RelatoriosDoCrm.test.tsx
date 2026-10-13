import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { crmApi } from '../api';
import type { RelatorioComercial } from '../types';
import { RelatoriosDoCrm } from './RelatoriosDoCrm';

const papel = vi.hoisted(() => ({ atual: 'ADMIN' }));
vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: () => ({ data: { papel: papel.atual } }) }));
vi.mock('@/features/usuarios/api', () => ({
  QUERY_KEY_USUARIOS: ['usuarios'],
  usuariosApi: {
    listar: vi.fn().mockResolvedValue({
      data: [{ id: 'u1', nome: 'Ana', email: 'a@x.com', papel: 'VENDEDOR', ativo: true }],
    }),
  },
}));
vi.mock('../api', async (original) => {
  const real = await original<typeof import('../api')>();
  return { ...real, crmApi: { ...real.crmApi, relatorio: vi.fn(), metas: vi.fn(), definirMeta: vi.fn(), removerMeta: vi.fn() } };
});

const total = (quantidade: number, valor_centavos: number) => ({ quantidade, valor_centavos });

function relatorio(extra: Partial<RelatorioComercial> = {}): RelatorioComercial {
  return {
    periodo: { de: '2026-10-01', ate: '2026-10-31' },
    resumo: { abertos: total(4, 40000), ganhos: total(2, 15000), perdidos: total(1, 7000), taxa_de_conversao: 0.6667 },
    funil: { id: 'f1', nome: 'Vendas', etapas: [{ id: 'e1', nome: 'Proposta', probabilidade: 40, quantidade: 3, valor_centavos: 30000 }] },
    previsao: { no_periodo: { quantidade: 2, valor_centavos: 20000, ponderada_centavos: 8000 }, sem_previsao: total(1, 500) },
    motivos_de_perda: [{ motivo_id: 'm1', nome: 'Preço', quantidade: 1, valor_centavos: 7000 }],
    vendedores: [{
      usuario_id: 'u1', nome: 'Ana', abertos: total(1, 1000), ganhos: total(2, 15000), perdidos: total(0, 0),
      meta: { unidade: 'VALOR', alvo: 30000, realizado: 15000, percentual: 50 },
    }],
    propostas: { enviadas: 5, aceitas: 2, recusadas: 1, valor_aceito_centavos: 15000, taxa_de_aceite: 0.6667 },
    faturado_centavos: 9000,
    ...extra,
  };
}

function renderizar() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(<QueryClientProvider client={client}><RelatoriosDoCrm /></QueryClientProvider>);
}

describe('RelatoriosDoCrm', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    papel.atual = 'ADMIN';
    vi.mocked(crmApi.metas).mockResolvedValue({ data: [] });
  });

  it('visão geral mostra ganhos, conversão, previsão e faturado', async () => {
    vi.mocked(crmApi.relatorio).mockResolvedValue({ data: relatorio() });
    renderizar();

    expect((await screen.findAllByText(/66,7\s?%/)).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/R\$\s*150,00/).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/R\$\s*80,00/).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/R\$\s*90,00/).length).toBeGreaterThan(0);
    expect(screen.getAllByText('Proposta').length).toBeGreaterThan(0);
  });

  it('conversão sem negócio fechado aparece como traço, não 0%', async () => {
    vi.mocked(crmApi.relatorio).mockResolvedValue({
      data: relatorio({ resumo: { abertos: total(0, 0), ganhos: total(0, 0), perdidos: total(0, 0), taxa_de_conversao: null } }),
    });
    renderizar();

    expect(await screen.findByText('—')).toBeInTheDocument();
    expect(screen.queryByText(/0,0\s?%/)).not.toBeInTheDocument();
  });

  it('faturado nulo (plano sem Fiscal) não mostra o cartão', async () => {
    vi.mocked(crmApi.relatorio).mockResolvedValue({ data: relatorio({ faturado_centavos: null }) });
    renderizar();

    await screen.findAllByText(/66,7\s?%/);
    expect(screen.queryByText(/faturado/i)).not.toBeInTheDocument();
  });

  it('aba Vendedores mostra o progresso da meta', async () => {
    vi.mocked(crmApi.relatorio).mockResolvedValue({ data: relatorio() });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: 'Vendedores' }));

    expect(screen.getAllByText('Ana').length).toBeGreaterThan(0);
    expect(screen.getAllByRole('progressbar', { name: /meta de ana/i })[0]).toHaveAttribute('aria-valuenow', '50');
  });

  it('vendedor restrito não vê a aba Vendedores', async () => {
    papel.atual = 'VENDEDOR';
    vi.mocked(crmApi.relatorio).mockResolvedValue({ data: relatorio({ vendedores: null }) });
    renderizar();

    await screen.findAllByText(/66,7\s?%/);
    expect(screen.queryByRole('button', { name: 'Vendedores' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Metas' })).not.toBeInTheDocument();
  });

  it('aba Perdas lista os motivos', async () => {
    vi.mocked(crmApi.relatorio).mockResolvedValue({ data: relatorio() });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: 'Perdas' }));

    expect(screen.getByText('Preço')).toBeInTheDocument();
  });

  it('admin define a meta de um vendedor no mês inteiro', async () => {
    vi.mocked(crmApi.relatorio).mockResolvedValue({ data: relatorio() });
    vi.mocked(crmApi.definirMeta).mockResolvedValue({
      data: { id: 'm1', usuario_id: 'u1', usuario_nome: 'Ana', mes: '2026-10', unidade: 'VALOR', alvo: 500000 },
    });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: 'Metas' }));
    await userEvent.selectOptions(await screen.findByLabelText(/vendedor/i, { selector: '#meta-vendedor' }), 'u1');
    await userEvent.type(screen.getByLabelText(/alvo/i), '5000');
    await userEvent.click(screen.getByRole('button', { name: /salvar meta/i }));

    expect(crmApi.definirMeta).toHaveBeenCalledWith(expect.objectContaining({ usuario_id: 'u1', unidade: 'VALOR', alvo: 500000 }));
  });

  it('erro da API mostra mensagem na página', async () => {
    vi.mocked(crmApi.relatorio).mockRejectedValue(new Error('falhou'));
    renderizar();

    expect(await screen.findByText(/não foi possível carregar o relatório/i)).toBeInTheDocument();
  });
});
