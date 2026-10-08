import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useAssinatura } from '@/features/assinatura/hooks/useAssinatura';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { ApiError } from '@/lib/api';
import { chavePublicaDoMercadoPago, montarCartao } from '@/lib/mercadopago';
import { cobrancaApi } from '../api';
import type { Cobranca } from '../types';
import { ListaDeFaturas } from './ListaDeFaturas';

vi.mock('../api', () => ({
  QUERY_KEY_FATURAS: ['faturas'],
  cobrancaApi: { checkout: vi.fn(), faturas: vi.fn(), cancelar: vi.fn(), pagar: vi.fn() },
}));
vi.mock('@/features/assinatura/hooks/useAssinatura', () => ({ QUERY_KEY_ASSINATURA: ['assinatura'], useAssinatura: vi.fn() }));
vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: vi.fn() }));
vi.mock('@/lib/mercadopago', () => ({ chavePublicaDoMercadoPago: vi.fn(), montarCartao: vi.fn() }));

const SEM_PIX = { pix_qr_code: null, pix_qr_code_base64: null, pix_expira_em: null } as const;

const EXCEDENTE: Cobranca = {
  ...SEM_PIX, id: 'e1', tipo: 'EXCEDENTE', valor_centavos: 300, vence_em: '2026-11-11', status: 'PENDENTE', metodo: null,
  boleto_url: null, boleto_linha_digitavel: null, pago_em: null, mes_referencia: '2026-10-01', documentos_excedentes: 3, valor_unitario_centavos: 100,
};
const COM_PIX: Cobranca = { ...EXCEDENTE, metodo: 'PIX', pix_qr_code: '000201PIXFAKE', pix_qr_code_base64: 'QVJDRA==', pix_expira_em: '2999-01-01T00:00:00-03:00' };
const MENSALIDADE_PAGA: Cobranca = {
  ...SEM_PIX, id: 'c0', tipo: 'ASSINATURA', valor_centavos: 9900, vence_em: '2026-09-10', status: 'PAGA', metodo: 'BOLETO',
  boleto_url: null, boleto_linha_digitavel: null, pago_em: '2026-09-09T10:00:00-03:00', mes_referencia: null, documentos_excedentes: null, valor_unitario_centavos: null,
};

function renderizar() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}><ListaDeFaturas /></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(useUsuario).mockReturnValue({ data: { papel: 'ADMIN' } } as never);
  vi.mocked(useAssinatura).mockReturnValue({ data: { situacao: 'ATIVA', teste_termina_em: null, plano: null, consumo: [], assinatura: null } } as never);
  vi.mocked(chavePublicaDoMercadoPago).mockReturnValue('TEST-pk');
  vi.mocked(montarCartao).mockResolvedValue(() => undefined);
});

afterEach(() => {
  vi.useRealTimers();
});

describe('Pagamento do excedente', () => {
  it('mostra o excedente com a conta do mês e o botão Pagar; mensalidade paga não mostra', async () => {
    vi.mocked(cobrancaApi.faturas).mockResolvedValue({ data: [EXCEDENTE, MENSALIDADE_PAGA] });
    renderizar();

    expect(await screen.findByText(/Documentos acima do limite em outubro de 2026: 3 × R\$\s*1,00/)).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Pagar' })).toHaveLength(1);
  });

  it('PIX: gera, mostra a imagem do QR, o código e copia', async () => {
    vi.mocked(cobrancaApi.faturas).mockResolvedValue({ data: [EXCEDENTE] });
    vi.mocked(cobrancaApi.pagar).mockResolvedValue({ data: COM_PIX });
    const escrever = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { value: { writeText: escrever }, configurable: true });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: 'Pagar' }));
    await userEvent.click(screen.getByRole('button', { name: 'Gerar PIX' }));

    await waitFor(() => expect(cobrancaApi.pagar).toHaveBeenCalledWith('e1', { metodo: 'PIX', token_do_cartao: null }));
    expect(await screen.findByRole('img', { name: 'QR code do PIX' })).toHaveAttribute('src', 'data:image/png;base64,QVJDRA==');
    expect(screen.getByText('000201PIXFAKE')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Copiar código' }));
    expect(escrever).toHaveBeenCalledWith('000201PIXFAKE');
  });

  it('cartão: o brick devolve o token e o pagamento o envia com o valor da cobrança', async () => {
    let enviar: ((token: string) => Promise<void>) | undefined;
    vi.mocked(montarCartao).mockImplementation(async (opcoes) => {
      enviar = opcoes.aoEnviar;
      return () => undefined;
    });
    vi.mocked(cobrancaApi.faturas).mockResolvedValue({ data: [EXCEDENTE] });
    vi.mocked(cobrancaApi.pagar).mockResolvedValue({ data: { ...EXCEDENTE, metodo: 'CARTAO', status: 'PAGA' } });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: 'Pagar' }));
    await userEvent.click(screen.getByRole('radio', { name: 'Cartão' }));
    await waitFor(() => expect(enviar).toBeDefined());
    await enviar?.('tok_9');

    await waitFor(() => expect(cobrancaApi.pagar).toHaveBeenCalledWith('e1', { metodo: 'CARTAO', token_do_cartao: 'tok_9' }));
    expect(vi.mocked(montarCartao).mock.calls[0][0].valorReais).toBe(3);
  });

  it('sem chave pública o cartão fica indisponível e o PIX continua', async () => {
    vi.mocked(chavePublicaDoMercadoPago).mockReturnValue(null);
    vi.mocked(cobrancaApi.faturas).mockResolvedValue({ data: [EXCEDENTE] });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: 'Pagar' }));
    await userEvent.click(screen.getByRole('radio', { name: 'Cartão' }));

    expect(screen.getByText('Pagamento por cartão indisponível nesta instalação.')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('radio', { name: 'PIX' }));
    expect(screen.getByRole('button', { name: 'Gerar PIX' })).toBeInTheDocument();
  });

  it('com PIX gerado a lista é recarregada até a cobrança ser paga', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.mocked(cobrancaApi.faturas)
      .mockResolvedValueOnce({ data: [COM_PIX] })
      .mockResolvedValue({ data: [{ ...COM_PIX, status: 'PAGA', pago_em: '2026-11-02T10:00:00-03:00' }] });
    renderizar();

    expect(await screen.findByText('Pendente')).toBeInTheDocument();
    await vi.advanceTimersByTimeAsync(3500);

    expect(await screen.findByText('Paga')).toBeInTheDocument();
    expect(vi.mocked(cobrancaApi.faturas).mock.calls.length).toBeGreaterThanOrEqual(2);
  });

  it('erro da API aparece em alerta', async () => {
    vi.mocked(cobrancaApi.faturas).mockResolvedValue({ data: [EXCEDENTE] });
    vi.mocked(cobrancaApi.pagar).mockRejectedValue(new ApiError(502, 'Não foi possível falar com o Mercado Pago. Tente novamente.', {}, { codigo: 'BILLING_GATEWAY_FALHOU' }));
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: 'Pagar' }));
    await userEvent.click(screen.getByRole('button', { name: 'Gerar PIX' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Não foi possível falar com o Mercado Pago.');
  });
});
