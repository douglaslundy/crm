import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAssinatura } from '@/features/assinatura/hooks/useAssinatura';
import type { Plano } from '@/features/assinatura/types';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { usePlanosPublicos } from '@/features/cadastro/hooks/usePlanosPublicos';
import { ApiError } from '@/lib/api';
import { chavePublicaDoMercadoPago, montarCartao } from '@/lib/mercadopago';
import { cobrancaApi } from '../api';
import type { AssinaturaDeCobranca, Cobranca } from '../types';
import { Assinar } from './Assinar';
import { ListaDeFaturas } from './ListaDeFaturas';

vi.mock('../api', () => ({
  QUERY_KEY_FATURAS: ['faturas'],
  cobrancaApi: { checkout: vi.fn(), faturas: vi.fn(), cancelar: vi.fn(), pagar: vi.fn(), trocarPlano: vi.fn(), cancelarTroca: vi.fn() },
}));
vi.mock('@/features/cadastro/hooks/usePlanosPublicos', () => ({ usePlanosPublicos: vi.fn() }));
vi.mock('@/features/assinatura/hooks/useAssinatura', () => ({ QUERY_KEY_ASSINATURA: ['assinatura'], useAssinatura: vi.fn() }));
vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: vi.fn() }));
vi.mock('@/lib/mercadopago', () => ({ chavePublicaDoMercadoPago: vi.fn(), montarCartao: vi.fn() }));

function plano(id: string, nome: string, mensal: number): Plano {
  return {
    id, nome, descricao: null, preco_mensal_centavos: mensal, preco_anual_centavos: mensal * 10, dias_teste: 0,
    politica_excedente: 'BLOQUEAR', preco_documento_excedente_centavos: null, modulos: ['FISCAL_NFE'],
    limites: { USUARIOS: 3, CLIENTES: 100, PRODUTOS: 100, SERVICOS: 100, DOCUMENTOS_MES: 100, API_REQUISICOES_MIN: 0 }, ativo: true, visivel: true, ordem: 0,
  };
}

const ATUAL = plano('11111111-1111-4111-8111-111111111111', 'Essencial', 9900);
const PRO = plano('22222222-2222-4222-8222-222222222222', 'Profissional', 15900);
const BASICO = plano('33333333-3333-4333-8333-333333333333', 'Básico', 4900);

const ASSINATURA: AssinaturaDeCobranca = {
  id: 'a1', plano_id: ATUAL.id, ciclo: 'MENSAL', metodo: 'CARTAO', situacao: 'ATIVA', paga_ate: '2026-10-30', cancelada_em: null,
  plano_proximo_id: null, plano_proximo_em: null,
};
const UPGRADE: Cobranca = {
  id: 'u1', tipo: 'UPGRADE', valor_centavos: 3000, vence_em: '2026-10-16', status: 'PENDENTE', metodo: null, boleto_url: null, boleto_linha_digitavel: null,
  pago_em: null, pix_qr_code: null, pix_qr_code_base64: null, pix_expira_em: null, mes_referencia: null, documentos_excedentes: null,
  valor_unitario_centavos: null, plano_destino_id: PRO.id,
};

function renderizar(ui: React.ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

function comAssinatura(assinatura: AssinaturaDeCobranca, situacao: 'ATIVA' | 'INADIMPLENTE' = 'ATIVA') {
  vi.mocked(useAssinatura).mockReturnValue({ data: { situacao, teste_termina_em: null, plano: ATUAL, consumo: [], assinatura } } as never);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(useUsuario).mockReturnValue({ data: { papel: 'ADMIN' } } as never);
  vi.mocked(usePlanosPublicos).mockReturnValue({ data: [ATUAL, PRO, BASICO], isPending: false, isError: false, isSuccess: true } as never);
  vi.mocked(chavePublicaDoMercadoPago).mockReturnValue('TEST-pk');
  vi.mocked(montarCartao).mockResolvedValue(() => undefined);
  vi.mocked(cobrancaApi.faturas).mockResolvedValue({ data: [] });
  comAssinatura(ASSINATURA);
});

describe('Trocar de plano', () => {
  it('assinante ativo e em dia vê os outros planos para trocar, não o atual', async () => {
    renderizar(<Assinar />);

    expect(await screen.findByText(/Sua assinatura está ativa até 30\/10\/2026/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Trocar para Profissional' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Trocar para Básico' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Trocar para Essencial' })).not.toBeInTheDocument();
  });

  it('upgrade mostra a diferença do servidor e abre o pagamento', async () => {
    vi.mocked(cobrancaApi.trocarPlano).mockResolvedValue({ data: { tipo: 'UPGRADE', cobranca: UPGRADE, assinatura: ASSINATURA } });
    renderizar(<Assinar />);

    await userEvent.click(await screen.findByRole('button', { name: 'Trocar para Profissional' }));

    await waitFor(() => expect(cobrancaApi.trocarPlano).toHaveBeenCalledWith(PRO.id));
    expect(await screen.findByText(/Troca de plano: diferença de R\$\s*30,00/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Gerar PIX' })).toBeInTheDocument();
  });

  it('downgrade mostra a data em que vale e permite cancelar a troca', async () => {
    const agendada = { ...ASSINATURA, plano_proximo_id: BASICO.id, plano_proximo_em: '2026-10-30' };
    vi.mocked(cobrancaApi.trocarPlano).mockResolvedValue({ data: { tipo: 'DOWNGRADE', cobranca: null, assinatura: agendada } });
    vi.mocked(cobrancaApi.cancelarTroca).mockResolvedValue({ data: ASSINATURA });
    renderizar(<Assinar />);

    await userEvent.click(await screen.findByRole('button', { name: 'Trocar para Básico' }));
    expect(await screen.findByText(/A troca para Básico vale em 30\/10\/2026/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Trocar para/ })).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Cancelar troca' }));
    await waitFor(() => expect(cobrancaApi.cancelarTroca).toHaveBeenCalledTimes(1));
  });

  it('assinatura com troca agendada mostra o aviso e esconde os botões de trocar', async () => {
    comAssinatura({ ...ASSINATURA, plano_proximo_id: BASICO.id, plano_proximo_em: '2026-10-30' });
    renderizar(<Assinar />);

    expect(await screen.findByText(/A troca para Básico vale em 30\/10\/2026/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Trocar para/ })).not.toBeInTheDocument();
  });

  it('plano que não cabe mostra os recursos acima do limite', async () => {
    vi.mocked(cobrancaApi.trocarPlano).mockRejectedValue(
      new ApiError(422, 'O uso atual da empresa passa os limites do plano escolhido.', {}, {
        codigo: 'PLANO_EXCEDIDO', excessos: [{ recurso: 'USUARIOS', uso: 5, limite: 3 }],
      }),
    );
    renderizar(<Assinar />);

    await userEvent.click(await screen.findByRole('button', { name: 'Trocar para Básico' }));

    const alerta = await screen.findByRole('alert');
    expect(alerta).toHaveTextContent('O uso atual da empresa passa os limites do plano escolhido.');
    expect(alerta).toHaveTextContent('Usuários: em uso 5, limite 3');
  });

  it('conta inadimplente não vê os botões de trocar', async () => {
    comAssinatura(ASSINATURA, 'INADIMPLENTE');
    renderizar(<Assinar />);

    expect(await screen.findByText(/pagamento em atraso/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Trocar para/ })).not.toBeInTheDocument();
  });

  it('a fatura do upgrade mostra a diferença e o botão Pagar', async () => {
    vi.mocked(cobrancaApi.faturas).mockResolvedValue({ data: [UPGRADE] });
    renderizar(<ListaDeFaturas />);

    expect(await screen.findByText(/Troca de plano: diferença de R\$\s*30,00/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Pagar' })).toBeInTheDocument();
  });
});
