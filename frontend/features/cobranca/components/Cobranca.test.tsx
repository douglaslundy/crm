import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAssinatura } from '@/features/assinatura/hooks/useAssinatura';
import type { Plano } from '@/features/assinatura/types';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { usePlanosPublicos } from '@/features/cadastro/hooks/usePlanosPublicos';
import { ApiError } from '@/lib/api';
import { chavePublicaDoMercadoPago, montarCartao } from '@/lib/mercadopago';
import { cobrancaApi } from '../api';
import type { Cobranca } from '../types';
import { Assinar } from './Assinar';
import { ListaDeFaturas } from './ListaDeFaturas';

vi.mock('../api', () => ({
  QUERY_KEY_FATURAS: ['faturas'],
  cobrancaApi: { checkout: vi.fn(), faturas: vi.fn(), cancelar: vi.fn(), pagar: vi.fn() },
}));
vi.mock('@/features/cadastro/hooks/usePlanosPublicos', () => ({ usePlanosPublicos: vi.fn() }));
vi.mock('@/features/assinatura/hooks/useAssinatura', () => ({ QUERY_KEY_ASSINATURA: ['assinatura'], useAssinatura: vi.fn() }));
vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: vi.fn() }));
vi.mock('@/lib/mercadopago', () => ({ chavePublicaDoMercadoPago: vi.fn(), montarCartao: vi.fn() }));

const SEM_EXCEDENTE = { pix_qr_code: null, pix_qr_code_base64: null, pix_expira_em: null, mes_referencia: null, documentos_excedentes: null, valor_unitario_centavos: null, plano_destino_id: null } as const;

const PLANO: Plano = {
  id: '11111111-1111-4111-8111-111111111111', nome: 'Essencial', descricao: null, preco_mensal_centavos: 9900, preco_anual_centavos: 99000,
  dias_teste: 0, politica_excedente: 'BLOQUEAR', preco_documento_excedente_centavos: null, modulos: ['FISCAL_NFE'],
  limites: { USUARIOS: 3, CLIENTES: 100, PRODUTOS: 100, SERVICOS: 100, DOCUMENTOS_MES: 100, API_REQUISICOES_MIN: 0 }, ativo: true, visivel: true, ordem: 0,
};
const PLANO_SEM_ANUAL: Plano = { ...PLANO, id: '22222222-2222-4222-8222-222222222222', nome: 'Básico', preco_mensal_centavos: 4900, preco_anual_centavos: null };

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

function comPapel(papel: string) {
  vi.mocked(useUsuario).mockReturnValue({ data: { papel } } as never);
}

beforeEach(() => {
  vi.clearAllMocks();
  comPapel('ADMIN');
  vi.mocked(usePlanosPublicos).mockReturnValue({ data: [PLANO, PLANO_SEM_ANUAL], isPending: false, isError: false, isSuccess: true } as never);
  vi.mocked(useAssinatura).mockReturnValue({ data: { situacao: 'TESTE', teste_termina_em: null, plano: null, consumo: [], assinatura: null } } as never);
  vi.mocked(chavePublicaDoMercadoPago).mockReturnValue('TEST-pk');
  vi.mocked(montarCartao).mockResolvedValue(() => undefined);
});

describe('Assinar (planos e checkout)', () => {
  it('lista os planos com preço mensal e anual e desabilita o ciclo sem preço', async () => {
    renderizar(<Assinar />);

    expect(await screen.findByRole('heading', { name: 'Essencial' })).toBeInTheDocument();
    expect(screen.getByText(/R\$\s*99,00/)).toBeInTheDocument();
    expect(screen.getByText(/R\$\s*990,00\/ano/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Assinar Essencial' }));
    expect(screen.getByRole('radio', { name: 'Anual' })).toBeEnabled();
    await userEvent.click(screen.getByRole('button', { name: 'Assinar Básico' }));
    expect(screen.getByRole('radio', { name: 'Anual' })).toBeDisabled();
  });

  it('boleto: chama o checkout com plano, ciclo e método e mostra o link e a linha digitável', async () => {
    vi.mocked(cobrancaApi.checkout).mockResolvedValue({
      data: {
        assinatura: { id: 'a1', plano_id: PLANO.id, ciclo: 'MENSAL', metodo: 'BOLETO', situacao: 'PENDENTE', paga_ate: null, cancelada_em: null, plano_proximo_id: null, plano_proximo_em: null },
        cobranca: { ...SEM_EXCEDENTE, id: 'c1', tipo: 'ASSINATURA', valor_centavos: 9900, vence_em: '2026-10-10', status: 'PENDENTE', metodo: 'BOLETO', boleto_url: 'https://mp.test/b/1', boleto_linha_digitavel: '1234.5678', pago_em: null },
      },
    });
    renderizar(<Assinar />);

    await userEvent.click(await screen.findByRole('button', { name: 'Assinar Essencial' }));
    await userEvent.click(screen.getByRole('radio', { name: 'Boleto' }));
    await userEvent.click(screen.getByRole('button', { name: 'Gerar boleto' }));

    await waitFor(() => expect(cobrancaApi.checkout).toHaveBeenCalledWith({ plano_id: PLANO.id, ciclo: 'MENSAL', metodo: 'BOLETO', token_do_cartao: null }));
    expect(await screen.findByRole('link', { name: 'Abrir boleto' })).toHaveAttribute('href', 'https://mp.test/b/1');
    expect(screen.getByText('1234.5678')).toBeInTheDocument();
  });

  it('cartão: o brick devolve o token e o checkout o envia', async () => {
    let enviar: ((token: string) => Promise<void>) | undefined;
    vi.mocked(montarCartao).mockImplementation(async (opcoes) => {
      enviar = opcoes.aoEnviar;
      return () => undefined;
    });
    vi.mocked(cobrancaApi.checkout).mockResolvedValue({
      data: {
        assinatura: { id: 'a1', plano_id: PLANO.id, ciclo: 'MENSAL', metodo: 'CARTAO', situacao: 'PENDENTE', paga_ate: null, cancelada_em: null, plano_proximo_id: null, plano_proximo_em: null },
        cobranca: { ...SEM_EXCEDENTE, id: 'c1', tipo: 'ASSINATURA', valor_centavos: 9900, vence_em: '2026-10-07', status: 'PENDENTE', metodo: 'CARTAO', boleto_url: null, boleto_linha_digitavel: null, pago_em: null },
      },
    });
    renderizar(<Assinar />);

    await userEvent.click(await screen.findByRole('button', { name: 'Assinar Essencial' }));
    await waitFor(() => expect(enviar).toBeDefined());
    await enviar?.('tok_1');

    await waitFor(() => expect(cobrancaApi.checkout).toHaveBeenCalledWith({ plano_id: PLANO.id, ciclo: 'MENSAL', metodo: 'CARTAO', token_do_cartao: 'tok_1' }));
    expect(await screen.findByText('Assinatura enviada, aguardando confirmação do pagamento.')).toBeInTheDocument();
    expect(vi.mocked(montarCartao).mock.calls[0][0].valorReais).toBe(99);
  });

  it('sem chave pública o cartão fica indisponível e o boleto continua', async () => {
    vi.mocked(chavePublicaDoMercadoPago).mockReturnValue(null);
    renderizar(<Assinar />);

    await userEvent.click(await screen.findByRole('button', { name: 'Assinar Essencial' }));

    expect(screen.getByText('Pagamento por cartão indisponível nesta instalação.')).toBeInTheDocument();
    expect(montarCartao).not.toHaveBeenCalled();
    await userEvent.click(screen.getByRole('radio', { name: 'Boleto' }));
    expect(screen.getByRole('button', { name: 'Gerar boleto' })).toBeInTheDocument();
  });

  it('erro da API aparece em alerta', async () => {
    vi.mocked(cobrancaApi.checkout).mockRejectedValue(new ApiError(409, 'Esta empresa já tem uma assinatura.', {}, { codigo: 'BILLING_ASSINATURA_ATIVA' }));
    renderizar(<Assinar />);

    await userEvent.click(await screen.findByRole('button', { name: 'Assinar Essencial' }));
    await userEvent.click(screen.getByRole('radio', { name: 'Boleto' }));
    await userEvent.click(screen.getByRole('button', { name: 'Gerar boleto' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Esta empresa já tem uma assinatura.');
  });

  it.each(['INADIMPLENTE', 'SUSPENSA'] as const)('assinatura ativa de quem está %s oferece só o boleto de regularização', async (situacao) => {
    const ativa = { id: 'a1', plano_id: PLANO.id, ciclo: 'ANUAL', metodo: 'BOLETO', situacao: 'ATIVA', paga_ate: '2026-10-01', cancelada_em: null, plano_proximo_id: null, plano_proximo_em: null } as const;
    vi.mocked(useAssinatura).mockReturnValue({ data: { situacao, teste_termina_em: null, plano: PLANO, consumo: [], assinatura: ativa } } as never);
    vi.mocked(cobrancaApi.checkout).mockResolvedValue({
      data: {
        assinatura: ativa,
        cobranca: { ...SEM_EXCEDENTE, id: 'c2', tipo: 'ASSINATURA', valor_centavos: 99000, vence_em: '2026-10-10', status: 'PENDENTE', metodo: 'BOLETO', boleto_url: 'https://mp.test/b/2', boleto_linha_digitavel: '9999', pago_em: null },
      },
    });
    renderizar(<Assinar />);

    expect(await screen.findByText(/pagamento em atraso/i)).toBeInTheDocument();
    expect(screen.queryByRole('radio')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Assinar/ })).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Gerar boleto de regularização' }));

    await waitFor(() => expect(cobrancaApi.checkout).toHaveBeenCalledWith({ plano_id: PLANO.id, ciclo: 'ANUAL', metodo: 'BOLETO', token_do_cartao: null }));
    expect(await screen.findByRole('link', { name: 'Abrir boleto' })).toHaveAttribute('href', 'https://mp.test/b/2');
  });

  it('assinatura ativa e em dia só mostra o aviso de ativa', async () => {
    vi.mocked(useAssinatura).mockReturnValue({ data: { situacao: 'ATIVA', teste_termina_em: null, plano: PLANO, consumo: [], assinatura: { id: 'a1', plano_id: PLANO.id, ciclo: 'MENSAL', metodo: 'BOLETO', situacao: 'ATIVA', paga_ate: '2026-11-01', cancelada_em: null, plano_proximo_id: null, plano_proximo_em: null } } } as never);
    renderizar(<Assinar />);

    expect(await screen.findByText(/Sua assinatura está ativa até 01\/11\/2026/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Gerar boleto/ })).not.toBeInTheDocument();
  });

  it('quem não é proprietário nem admin vê o aviso e nenhum botão de assinar', async () => {
    comPapel('VENDEDOR');
    renderizar(<Assinar />);

    expect(await screen.findByText(/Só o proprietário ou um administrador/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Assinar/ })).not.toBeInTheDocument();
  });
});

describe('ListaDeFaturas', () => {
  const FATURA: Cobranca = { ...SEM_EXCEDENTE, id: 'c1', tipo: 'ASSINATURA', valor_centavos: 9900, vence_em: '2026-10-10', status: 'PENDENTE', metodo: 'BOLETO', boleto_url: 'https://mp.test/b/1', boleto_linha_digitavel: '1234.5678', pago_em: null };

  it('lista valor, vencimento, status e o link do boleto', async () => {
    vi.mocked(cobrancaApi.faturas).mockResolvedValue({ data: [FATURA, { ...FATURA, id: 'c0', status: 'PAGA', boleto_url: null, vence_em: '2026-09-10', pago_em: '2026-09-09T10:00:00-03:00' }] });
    renderizar(<ListaDeFaturas />);

    expect(await screen.findByText(/10\/10\/2026/)).toBeInTheDocument();
    expect(screen.getByText('Pendente')).toBeInTheDocument();
    expect(screen.getByText('Paga')).toBeInTheDocument();
    expect(screen.getAllByText(/R\$\s*99,00/)).toHaveLength(2);
    expect(screen.getByRole('link', { name: 'Abrir boleto' })).toHaveAttribute('href', 'https://mp.test/b/1');
  });

  it('cancelar a assinatura pede confirmação e chama a API', async () => {
    vi.mocked(cobrancaApi.faturas).mockResolvedValue({ data: [FATURA] });
    const ativa = { id: 'a1', plano_id: PLANO.id, ciclo: 'MENSAL', metodo: 'BOLETO', situacao: 'ATIVA', paga_ate: '2026-11-07', cancelada_em: null, plano_proximo_id: null, plano_proximo_em: null } as const;
    vi.mocked(useAssinatura).mockReturnValue({ data: { situacao: 'ATIVA', teste_termina_em: null, plano: null, consumo: [], assinatura: ativa } } as never);
    vi.mocked(cobrancaApi.cancelar).mockResolvedValue({ data: { ...ativa, situacao: 'CANCELADA', cancelada_em: '2026-10-07T10:00:00-03:00' } });
    renderizar(<ListaDeFaturas />);

    await userEvent.click(await screen.findByRole('button', { name: 'Cancelar assinatura' }));
    expect(cobrancaApi.cancelar).not.toHaveBeenCalled();
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar cancelamento' }));

    await waitFor(() => expect(cobrancaApi.cancelar).toHaveBeenCalledTimes(1));
  });

  it('sem faturas mostra a mensagem vazia', async () => {
    vi.mocked(cobrancaApi.faturas).mockResolvedValue({ data: [] });
    renderizar(<ListaDeFaturas />);

    expect(await screen.findByText('Nenhuma fatura ainda.')).toBeInTheDocument();
  });

  it('quem não é proprietário nem admin vê só o aviso', async () => {
    comPapel('LEITURA');
    renderizar(<ListaDeFaturas />);

    expect(await screen.findByText(/Só o proprietário ou um administrador/)).toBeInTheDocument();
    expect(cobrancaApi.faturas).not.toHaveBeenCalled();
  });
});
