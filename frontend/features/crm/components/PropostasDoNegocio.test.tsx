import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { crmApi } from '../api';
import type { Negocio, Proposta } from '../types';
import { PropostasDoNegocio } from './PropostasDoNegocio';

const erroToast = vi.hoisted(() => vi.fn());
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: (m: string) => erroToast(m), info: vi.fn() } }));
vi.mock('../api', async (original) => {
  const real = await original<typeof import('../api')>();
  return {
    ...real,
    crmApi: {
      ...real.crmApi,
      propostas: vi.fn(),
      criarProposta: vi.fn(),
      enviarProposta: vi.fn(),
      cancelarProposta: vi.fn(),
      gerarNotaDaProposta: vi.fn(),
    },
  };
});

const negocio = { id: 'n1', status: 'ABERTO', cliente: { id: 'c1', nome: 'Ana', telefone: null, email: null, estagio: 'CLIENTE' } } as unknown as Negocio;

function proposta(extra: Partial<Proposta>): Proposta {
  return {
    id: 'p1', numero: 1, status: 'RASCUNHO', negocio_id: 'n1', cliente_id: 'c1', validade_dias: 15, validade_em: null,
    desconto_centavos: 0, subtotal_centavos: 3000, total_centavos: 3000, observacoes: null, enviada_em: null,
    respondida_em: null, aceite_nome: null, motivo_recusa: null, notas_ids: [], itens: [], link: null, ...extra,
  };
}

function renderizar(opcoes: { podeEscrever?: boolean; temFiscal?: boolean } = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <PropostasDoNegocio negocio={negocio} podeEscrever={opcoes.podeEscrever ?? true} temFiscal={opcoes.temFiscal ?? true} />
    </QueryClientProvider>,
  );
}

describe('PropostasDoNegocio', () => {
  beforeEach(() => vi.clearAllMocks());

  it('rascunho mostra status e total, com Enviar e Cancelar mas sem copiar link', async () => {
    vi.mocked(crmApi.propostas).mockResolvedValue({ data: [proposta({})] });
    renderizar();

    expect(await screen.findByText(/proposta #1/i)).toBeInTheDocument();
    expect(screen.getByText('Rascunho')).toBeInTheDocument();
    expect(screen.getByText(/R\$\s*30,00/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Enviar' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancelar' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /copiar link/i })).not.toBeInTheDocument();
  });

  it('enviar chama a API', async () => {
    vi.mocked(crmApi.propostas).mockResolvedValue({ data: [proposta({})] });
    vi.mocked(crmApi.enviarProposta).mockResolvedValue({ data: proposta({ status: 'ENVIADA' }) });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: 'Enviar' }));

    expect(crmApi.enviarProposta).toHaveBeenCalledWith('p1');
  });

  it('enviada permite copiar o link', async () => {
    const copiar = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { value: { writeText: copiar }, configurable: true });
    vi.mocked(crmApi.propostas).mockResolvedValue({
      data: [proposta({ status: 'ENVIADA', link: 'https://app.test/p/abc' })],
    });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: /copiar link/i }));

    expect(copiar).toHaveBeenCalledWith('https://app.test/p/abc');
  });

  it('aceita com Fiscal mostra Emitir nota e depois os links dos rascunhos', async () => {
    vi.mocked(crmApi.propostas).mockResolvedValue({ data: [proposta({ status: 'ACEITA', aceite_nome: 'Maria' })] });
    vi.mocked(crmApi.gerarNotaDaProposta).mockResolvedValue({ data: proposta({ status: 'ACEITA', notas_ids: ['n9'] }) });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: /emitir nota/i }));
    expect(crmApi.gerarNotaDaProposta).toHaveBeenCalledWith('p1');
  });

  it('aceita com rascunhos gerados mostra links para as notas', async () => {
    vi.mocked(crmApi.propostas).mockResolvedValue({ data: [proposta({ status: 'ACEITA', notas_ids: ['n9', 'n10'] })] });
    renderizar();

    const links = await screen.findAllByRole('link', { name: /abrir rascunho/i });
    expect(links.map((l) => l.getAttribute('href'))).toEqual(['/notas/n9', '/notas/n10']);
    expect(screen.queryByRole('button', { name: /emitir nota/i })).not.toBeInTheDocument();
  });

  it('aceita sem Fiscal no plano não oferece Emitir nota', async () => {
    vi.mocked(crmApi.propostas).mockResolvedValue({ data: [proposta({ status: 'ACEITA' })] });
    renderizar({ temFiscal: false });

    await screen.findByText(/proposta #1/i);
    expect(screen.queryByRole('button', { name: /emitir nota/i })).not.toBeInTheDocument();
  });

  it('sem permissão de escrita esconde as ações e mantém o PDF', async () => {
    vi.mocked(crmApi.propostas).mockResolvedValue({ data: [proposta({ status: 'ENVIADA', link: 'x' })] });
    renderizar({ podeEscrever: false });

    expect(await screen.findByRole('link', { name: /baixar pdf/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Cancelar' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /nova proposta/i })).not.toBeInTheDocument();
  });

  it('erro da API vira aviso para o usuário', async () => {
    vi.mocked(crmApi.propostas).mockResolvedValue({ data: [] });
    vi.mocked(crmApi.criarProposta).mockRejectedValue(new ApiError(422, 'Adicione ao menos um item ao negócio antes de criar a proposta.'));
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: /nova proposta/i }));

    await vi.waitFor(() => expect(erroToast).toHaveBeenCalledWith(expect.stringContaining('ao menos um item')));
  });
});
