import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { clientesApi } from '@/features/clientes/api';
import { produtosApi } from '@/features/produtos/api';
import { notasApi } from '../api';
import type { Nota } from '../types';
import { ListaDeNotas } from './ListaDeNotas';
import { NotaForm } from './NotaForm';
import { StatusDaNota } from './StatusDaNota';

vi.mock('../api', () => ({
  QUERY_KEY_NOTAS: ['notas'],
  urlDoXml: (id: string) => `http://api.test/api/app/notas/${id}/xml`,
  notasApi: { listar: vi.fn(), buscar: vi.fn(), status: vi.fn(), criar: vi.fn(), editar: vi.fn(), excluir: vi.fn(), emitir: vi.fn() },
}));

vi.mock('@/features/clientes/api', () => ({ QUERY_KEY_CLIENTES: ['clientes'], clientesApi: { listar: vi.fn() } }));
vi.mock('@/features/produtos/api', () => ({ QUERY_KEY_PRODUTOS: ['produtos'], produtosApi: { listar: vi.fn() } }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ push: vi.fn() }) }));

export function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

export const NOTA: Nota = {
  id: 'n1', status: 'AUTORIZADA', ambiente: 'HOMOLOGACAO', numero: 7, serie: '1', chave: '3'.repeat(44), protocolo: '135', cstat: '100', motivo: 'Autorizado',
  mensagem_erro: null, natureza_operacao: 'Venda de mercadoria', consumidor_final: true, subtotal_centavos: 2500, desconto_centavos: 0, total_centavos: 2500,
  informacoes_complementares: null, emitido_em: '2026-10-02T10:00:00-03:00', created_at: '2026-10-02T09:00:00-03:00', cliente: { id: 'c1', nome: 'Maria Teste' },
};

describe('StatusDaNota', () => {
  it('mostra o texto do status e o ambiente (nunca só cor)', () => {
    render(<StatusDaNota status="AUTORIZADA" ambiente="HOMOLOGACAO" />);
    expect(screen.getByText('Autorizada')).toBeInTheDocument();
    expect(screen.getByText('Homologação')).toBeInTheDocument();
  });
});

describe('ListaDeNotas', () => {
  it('lista as notas com número, cliente e total', async () => {
    vi.mocked(notasApi.listar).mockResolvedValue({ data: [NOTA] });
    renderizar(<ListaDeNotas />);

    expect(await screen.findByText('Maria Teste')).toBeInTheDocument();
    expect(screen.getByText(/Nº 7/)).toBeInTheDocument();
    expect(screen.getByText(/R\$\s*25,00/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Abrir nota 7/ })).toHaveAttribute('href', '/notas/n1');
  });

  it('filtra por status', async () => {
    vi.mocked(notasApi.listar).mockResolvedValue({ data: [] });
    renderizar(<ListaDeNotas />);
    await screen.findByText('Nenhuma nota encontrada.');

    await userEvent.selectOptions(screen.getByLabelText('Status'), 'REJEITADA');
    await waitFor(() => expect(notasApi.listar).toHaveBeenLastCalledWith('REJEITADA'));
  });

  it('avisa quando a lista falha ao carregar', async () => {
    vi.mocked(notasApi.listar).mockRejectedValue(new Error('x'));
    renderizar(<ListaDeNotas />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Não foi possível carregar as notas.');
  });
});

const CLIENTE_PF = { id: '11111111-1111-4111-8111-111111111111', tipo: 'PF', nome: 'Maria Teste', estagio: 'CLIENTE' };
const CLIENTE_PJ = { id: '33333333-3333-4333-8333-333333333333', tipo: 'PJ', nome: 'Empresa X', estagio: 'CLIENTE' };
const PRODUTO = { id: '22222222-2222-4222-8222-222222222222', sku: 'P-1', nome: 'Produto Um', preco_centavos: 1000 };

describe('NotaForm', () => {
  function preparar() {
    vi.mocked(clientesApi.listar).mockResolvedValue({ data: [CLIENTE_PF, CLIENTE_PJ] as never });
    vi.mocked(produtosApi.listar).mockResolvedValue({ data: [PRODUTO] as never });
  }

  it('cria o rascunho com itens e pagamento no formato da API', async () => {
    preparar();
    vi.mocked(notasApi.criar).mockResolvedValue({ data: { ...NOTA, id: 'novo', status: 'RASCUNHO' } });
    renderizar(<NotaForm />);

    await userEvent.selectOptions(await screen.findByLabelText('Cliente'), CLIENTE_PF.id);
    await userEvent.selectOptions(screen.getByLabelText('Produto 1'), PRODUTO.id);
    await userEvent.clear(screen.getByLabelText('Quantidade 1'));
    await userEvent.type(screen.getByLabelText('Quantidade 1'), '2,5');
    await userEvent.clear(screen.getByLabelText('Valor do pagamento 1'));
    await userEvent.type(screen.getByLabelText('Valor do pagamento 1'), '25,00');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar rascunho' }));

    await waitFor(() =>
      expect(notasApi.criar).toHaveBeenCalledWith(
        expect.objectContaining({
          cliente_id: CLIENTE_PF.id,
          itens: [{ produto_id: PRODUTO.id, quantidade: '2.5', valor_unitario_centavos: null }],
          pagamentos: [{ tpag: '01', xpag: null, valor_centavos: 2500 }],
        }),
      ),
    );
  });

  it('pergunta se é consumidor final só para cliente PJ', async () => {
    preparar();
    renderizar(<NotaForm />);

    await userEvent.selectOptions(await screen.findByLabelText('Cliente'), CLIENTE_PF.id);
    expect(screen.queryByLabelText('Consumidor final?')).not.toBeInTheDocument();

    await userEvent.selectOptions(screen.getByLabelText('Cliente'), CLIENTE_PJ.id);
    expect(screen.getByLabelText('Consumidor final?')).toBeInTheDocument();
  });

  it('mostra a descrição quando o pagamento é Outros', async () => {
    preparar();
    renderizar(<NotaForm />);
    await screen.findByLabelText('Cliente');

    expect(screen.queryByLabelText('Descrição do pagamento 1')).not.toBeInTheDocument();
    await userEvent.selectOptions(screen.getByLabelText('Forma de pagamento 1'), '99');
    expect(screen.getByLabelText('Descrição do pagamento 1')).toBeInTheDocument();
  });

  it('adiciona e remove itens', async () => {
    preparar();
    renderizar(<NotaForm />);
    await screen.findByLabelText('Cliente');

    await userEvent.click(screen.getByRole('button', { name: 'Adicionar item' }));
    expect(screen.getByLabelText('Produto 2')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Remover item 2' }));
    expect(screen.queryByLabelText('Produto 2')).not.toBeInTheDocument();
  });

  it('mostra o erro de bloqueio fiscal devolvido pelo servidor', async () => {
    preparar();
    const { ApiError } = await import('@/lib/api');
    vi.mocked(notasApi.criar).mockRejectedValue(new ApiError(422, 'O produto "Produto Um" está sem: NCM.', {}, { codigo: 'FISCAL_PRODUTO_INCOMPLETO' }));
    renderizar(<NotaForm />);

    await userEvent.selectOptions(await screen.findByLabelText('Cliente'), CLIENTE_PF.id);
    await userEvent.selectOptions(screen.getByLabelText('Produto 1'), PRODUTO.id);
    await userEvent.type(screen.getByLabelText('Quantidade 1'), '1');
    await userEvent.clear(screen.getByLabelText('Valor do pagamento 1'));
    await userEvent.type(screen.getByLabelText('Valor do pagamento 1'), '10,00');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar rascunho' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('está sem: NCM');
  });
});
