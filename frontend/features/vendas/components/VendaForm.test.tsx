import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { clientesApi } from '@/features/clientes/api';
import { produtosApi } from '@/features/produtos/api';
import { servicosApi } from '@/features/servicos/api';
import { ApiError } from '@/lib/api';
import { vendasApi } from '../api';
import { VendaForm } from './VendaForm';

const push = vi.fn();

vi.mock('../api', () => ({
  QUERY_KEY_VENDAS: ['vendas'],
  vendasApi: { buscar: vi.fn(), criar: vi.fn(), editar: vi.fn(), excluir: vi.fn(), emitir: vi.fn() },
}));
vi.mock('@/features/notas/api', () => ({ QUERY_KEY_NOTAS: ['notas'] }));
vi.mock('@/features/clientes/api', () => ({ QUERY_KEY_CLIENTES: ['clientes'], clientesApi: { listar: vi.fn() } }));
vi.mock('@/features/produtos/api', () => ({ QUERY_KEY_PRODUTOS: ['produtos'], produtosApi: { listar: vi.fn() } }));
vi.mock('@/features/servicos/api', () => ({ QUERY_KEY_SERVICOS: ['servicos'], servicosApi: { listar: vi.fn() } }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ push }) }));

const CLIENTE = { id: '11111111-1111-4111-8111-111111111111', tipo: 'PF', nome: 'Maria Teste', estagio: 'CLIENTE' };
const PRODUTO = { id: '22222222-2222-4222-8222-222222222222', nome: 'Filtro de óleo', preco_centavos: 1000 };
const SERVICO = { id: '55555555-5555-4555-8555-555555555555', nome: 'Troca de óleo', preco_centavos: 15000 };

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(clientesApi.listar).mockResolvedValue({ data: [CLIENTE] as never });
  vi.mocked(produtosApi.listar).mockResolvedValue({ data: [PRODUTO] as never });
  vi.mocked(servicosApi.listar).mockResolvedValue({ data: [SERVICO] as never });
});

async function preencherOBasico() {
  await userEvent.selectOptions(await screen.findByLabelText('Cliente'), CLIENTE.id);
  await userEvent.selectOptions(screen.getByLabelText('Produto 1'), PRODUTO.id);
  await userEvent.selectOptions(screen.getByLabelText('Serviço 1'), SERVICO.id);
}

describe('VendaForm', () => {
  it('mostra os blocos de produtos, serviços e pagamentos', async () => {
    renderizar(<VendaForm />);

    expect(await screen.findByText('Produtos (NF-e)')).toBeInTheDocument();
    expect(screen.getByText('Serviços (NFS-e)')).toBeInTheDocument();
    expect(screen.getByLabelText('Forma de pagamento 1')).toBeInTheDocument();
  });

  it('mostra a prévia do rateio do desconto entre a NF-e e a NFS-e', async () => {
    renderizar(<VendaForm />);
    await preencherOBasico();

    const desconto = screen.getByLabelText('Desconto (R$)');
    await userEvent.clear(desconto);
    await userEvent.type(desconto, '16,00');

    expect(await screen.findByText(/NF-e: desconto R\$\s*1,00, total R\$\s*9,00/)).toBeInTheDocument();
    expect(screen.getByText(/NFS-e: desconto R\$\s*15,00, total R\$\s*135,00/)).toBeInTheDocument();
    expect(screen.getByText(/Total da venda: R\$\s*144,00/)).toBeInTheDocument();
    expect(screen.getByText(/Os pagamentos devem somar o total da NF-e \(R\$\s*9,00\)/)).toBeInTheDocument();
  });

  it('avisa quando o desconto passa do total dos itens', async () => {
    renderizar(<VendaForm />);
    await preencherOBasico();

    const desconto = screen.getByLabelText('Desconto (R$)');
    await userEvent.clear(desconto);
    await userEvent.type(desconto, '999,00');

    expect(await screen.findByText('O desconto não pode passar do total dos itens.')).toBeInTheDocument();
  });

  it('sem escolher o serviço mostra a mensagem do campo e não chama a API', async () => {
    renderizar(<VendaForm />);
    await userEvent.selectOptions(await screen.findByLabelText('Cliente'), CLIENTE.id);
    await userEvent.selectOptions(screen.getByLabelText('Produto 1'), PRODUTO.id);
    await userEvent.click(screen.getByRole('button', { name: 'Salvar rascunho' }));

    expect(await screen.findByText('Escolha um serviço.')).toBeInTheDocument();
    expect(vendasApi.criar).not.toHaveBeenCalled();
  });

  it('salva a venda e vai para o detalhe', async () => {
    vi.mocked(vendasApi.criar).mockResolvedValue({ data: { id: 'v1', desconto_centavos: 1600, subtotal_centavos: 16000, total_centavos: 14400 } });
    renderizar(<VendaForm />);
    await preencherOBasico();
    const desconto = screen.getByLabelText('Desconto (R$)');
    await userEvent.clear(desconto);
    await userEvent.type(desconto, '16,00');
    const pagamento = screen.getByLabelText('Valor do pagamento 1');
    await userEvent.clear(pagamento);
    await userEvent.type(pagamento, '9,00');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar rascunho' }));

    await waitFor(() =>
      expect(vendasApi.criar).toHaveBeenCalledWith({
        cliente_id: CLIENTE.id, desconto_centavos: 1600, informacoes_complementares: null,
        itens_produtos: [{ produto_id: PRODUTO.id, quantidade: '1', valor_unitario_centavos: null }],
        itens_servicos: [{ servico_id: SERVICO.id, quantidade: '1', valor_unitario_centavos: null }],
        pagamentos: [{ tpag: '01', xpag: null, valor_centavos: 900 }],
      }),
    );
    await waitFor(() => expect(push).toHaveBeenCalledWith('/vendas/v1'));
  });

  it('mostra o erro da API no banner', async () => {
    vi.mocked(vendasApi.criar).mockRejectedValue(new ApiError(422, 'O desconto da venda deve ser menor que o total dos itens.', {}, { codigo: 'FISCAL_DESCONTO_INVALIDO' }));
    renderizar(<VendaForm />);
    await preencherOBasico();
    await userEvent.click(screen.getByRole('button', { name: 'Salvar rascunho' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('O desconto da venda deve ser menor');
  });
});
