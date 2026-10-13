import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { propostaPublicaApi } from '../publico';
import type { PropostaPublica } from '../types';
import { PaginaPublicaDaProposta } from './PaginaPublicaDaProposta';

vi.mock('../publico', () => ({
  propostaPublicaApi: { ler: vi.fn(), aceitar: vi.fn(), recusar: vi.fn(), urlDoPdf: (t: string) => `https://api.test/pdf/${t}` },
}));

function dados(extra: Partial<PropostaPublica> = {}): PropostaPublica {
  return {
    numero: 7, status: 'ENVIADA', empresa: { nome: 'Loja Boa', cnpj: '00.000.000/0001-00' }, cliente: { nome: 'Ana' },
    validade_em: '2026-11-01T00:00:00+00:00',
    itens: [{ tipo: 'PRODUTO', descricao: 'Caneca', quantidade: 2, valor_unitario_centavos: 1500, total_centavos: 3000 }],
    subtotal_centavos: 3000, desconto_centavos: 0, total_centavos: 3000, observacoes: null, aceite_nome: null,
    respondida_em: null, ...extra,
  };
}

describe('PaginaPublicaDaProposta', () => {
  beforeEach(() => vi.clearAllMocks());

  it('enviada: mostra itens e total; aceitar exige o nome e passa a mostrar aceita', async () => {
    vi.mocked(propostaPublicaApi.ler).mockResolvedValue({ data: dados() });
    vi.mocked(propostaPublicaApi.aceitar).mockResolvedValue({
      data: dados({ status: 'ACEITA', aceite_nome: 'Maria', respondida_em: '2026-10-20T10:00:00+00:00' }),
    });
    render(<PaginaPublicaDaProposta token="tok" />);

    expect(await screen.findByText('Caneca')).toBeInTheDocument();
    expect(screen.getByText(/Loja Boa/)).toBeInTheDocument();
    const botao = screen.getByRole('button', { name: /aceitar proposta/i });
    expect(botao).toBeDisabled();

    await userEvent.type(screen.getByLabelText(/seu nome/i), 'Maria');
    await userEvent.click(botao);

    expect(propostaPublicaApi.aceitar).toHaveBeenCalledWith('tok', 'Maria');
    expect(await screen.findByText(/aceita por Maria/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /aceitar proposta/i })).not.toBeInTheDocument();
  });

  it('recusar com motivo chama a API', async () => {
    vi.mocked(propostaPublicaApi.ler).mockResolvedValue({ data: dados() });
    vi.mocked(propostaPublicaApi.recusar).mockResolvedValue({ data: dados({ status: 'RECUSADA' }) });
    render(<PaginaPublicaDaProposta token="tok" />);

    await userEvent.click(await screen.findByRole('button', { name: /^recusar$/i }));
    await userEvent.type(screen.getByLabelText(/motivo/i), 'Preço');
    await userEvent.click(screen.getByRole('button', { name: /confirmar recusa/i }));

    expect(propostaPublicaApi.recusar).toHaveBeenCalledWith('tok', 'Preço');
    expect(await screen.findByText(/proposta recusada/i)).toBeInTheDocument();
  });

  it('404 mostra mensagem genérica', async () => {
    vi.mocked(propostaPublicaApi.ler).mockRejectedValue(new ApiError(404, 'Not found'));
    render(<PaginaPublicaDaProposta token="tok" />);

    expect(await screen.findByText(/não encontrada ou indisponível/i)).toBeInTheDocument();
  });

  it('expirada não oferece responder', async () => {
    vi.mocked(propostaPublicaApi.ler).mockResolvedValue({ data: dados({ status: 'EXPIRADA' }) });
    render(<PaginaPublicaDaProposta token="tok" />);

    expect(await screen.findByText(/expirou/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /aceitar proposta/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^recusar$/i })).not.toBeInTheDocument();
  });

  it('erro de regra no aceite aparece na página', async () => {
    vi.mocked(propostaPublicaApi.ler).mockResolvedValue({ data: dados() });
    vi.mocked(propostaPublicaApi.aceitar).mockRejectedValue(new ApiError(422, 'Esta proposta expirou. Peça uma nova ao vendedor.'));
    render(<PaginaPublicaDaProposta token="tok" />);

    await userEvent.type(await screen.findByLabelText(/seu nome/i), 'Maria');
    await userEvent.click(screen.getByRole('button', { name: /aceitar proposta/i }));

    expect(await screen.findByText(/peça uma nova ao vendedor/i)).toBeInTheDocument();
  });

  it('já aceita mostra quem aceitou e baixa o PDF', async () => {
    vi.mocked(propostaPublicaApi.ler).mockResolvedValue({ data: dados({ status: 'ACEITA', aceite_nome: 'Maria' }) });
    render(<PaginaPublicaDaProposta token="tok" />);

    expect(await screen.findByText(/aceita por Maria/i)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /baixar pdf/i })).toHaveAttribute('href', 'https://api.test/pdf/tok');
  });
});
