import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { clientesApi } from '@/features/clientes/api';
import { produtosApi } from '@/features/produtos/api';
import { notasApi } from '../api';
import type { Nota } from '../types';
import { DetalheDaNota } from './DetalheDaNota';
import { ListaDeNotas } from './ListaDeNotas';
import { NotaForm } from './NotaForm';
import { StatusDaNota } from './StatusDaNota';

vi.mock('../api', () => ({
  QUERY_KEY_NOTAS: ['notas'],
  urlDoXml: (id: string) => `http://api.test/api/app/notas/${id}/xml`,
  urlDoXmlCancelamento: (id: string) => `http://api.test/api/app/notas/${id}/xml-cancelamento`,
  QUERY_KEY_INUTILIZACOES: ['inutilizacoes'],
  inutilizacoesApi: { listar: vi.fn(), criar: vi.fn() },
  notasApi: { listar: vi.fn(), buscar: vi.fn(), status: vi.fn(), criar: vi.fn(), editar: vi.fn(), excluir: vi.fn(), emitir: vi.fn(), cancelar: vi.fn() },
}));

vi.mock('@/features/clientes/api', () => ({ QUERY_KEY_CLIENTES: ['clientes'], clientesApi: { listar: vi.fn() } }));
vi.mock('@/features/produtos/api', () => ({ QUERY_KEY_PRODUTOS: ['produtos'], produtosApi: { listar: vi.fn() } }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ push: vi.fn() }) }));
vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: vi.fn() }));

export function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

export const NOTA: Nota = {
  id: 'n1', status: 'AUTORIZADA', ambiente: 'HOMOLOGACAO', numero: 7, serie: '1', chave: '3'.repeat(44), protocolo: '135', cstat: '100', motivo: 'Autorizado',
  cancelamento_justificativa: null, cancelado_em: null, cancelamento_protocolo: null, cancelamento_cstat: null, cancelamento_motivo: null,
  mensagem_erro: null, natureza_operacao: 'Venda de mercadoria', consumidor_final: true, subtotal_centavos: 2500, desconto_centavos: 0, total_centavos: 2500,
  informacoes_complementares: null, emitido_em: '2026-10-02T10:00:00-03:00', created_at: '2026-10-02T09:00:00-03:00', cliente: { id: 'c1', nome: 'Maria Teste' },
};

describe('StatusDaNota', () => {
  it('mostra Cancelando como texto', () => {
    render(<StatusDaNota status="CANCELANDO" ambiente="PRODUCAO" />);
    expect(screen.getByText('Cancelando')).toBeInTheDocument();
  });

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

const rascunho: Nota = {
  ...NOTA, status: 'RASCUNHO', numero: null, serie: null, chave: null, protocolo: null, cstat: null, motivo: null, ambiente: null, emitido_em: null,
  itens: [], pagamentos: [], eventos: [{ status_de: null, status_para: 'RASCUNHO', detalhe: 'Rascunho criado', em: '2026-10-02T09:00:00-03:00' }],
};

function comPapel(papel: string) {
  vi.mocked(useUsuario).mockReturnValue({ data: { papel } } as never);
}

describe('DetalheDaNota', () => {
  it('emite a nota e mostra o status de processamento', async () => {
    comPapel('ADMIN');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: rascunho });
    vi.mocked(notasApi.emitir).mockResolvedValue({ data: { id: 'n1', status: 'PROCESSANDO', numero: 1, serie: '1', chave: null, cstat: null, motivo: null, mensagem_erro: null, cancelamento_cstat: null, cancelamento_motivo: null } });
    renderizar(<DetalheDaNota id="n1" />);

    await userEvent.click(await screen.findByRole('button', { name: 'Emitir NF-e' }));
    await waitFor(() => expect(notasApi.emitir).toHaveBeenCalledWith('n1'));
  });

  it('mostra cStat e motivo da rejeição e oferece corrigir', async () => {
    comPapel('FISCAL');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: { ...rascunho, status: 'REJEITADA', cstat: '232', motivo: 'IE do destinatário não informada', ambiente: 'HOMOLOGACAO', numero: 3, serie: '1' } });
    renderizar(<DetalheDaNota id="n1" />);

    expect(await screen.findByRole('alert')).toHaveTextContent('cStat 232: IE do destinatário não informada');
    expect(screen.getByRole('button', { name: 'Corrigir e emitir de novo' })).toBeInTheDocument();
  });

  it('mostra o erro técnico sem chamar de rejeição', async () => {
    comPapel('ADMIN');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: { ...rascunho, status: 'ERRO', mensagem_erro: 'Falha técnica ao emitir: timeout' } });
    renderizar(<DetalheDaNota id="n1" />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Falha técnica ao emitir: timeout');
    expect(screen.queryByText(/cStat/)).not.toBeInTheDocument();
  });

  it('autorizada mostra chave, protocolo e o link do XML', async () => {
    comPapel('LEITURA');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: NOTA });
    renderizar(<DetalheDaNota id="n1" />);

    expect(await screen.findByText(NOTA.chave as string)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Baixar XML' })).toHaveAttribute('href', 'http://api.test/api/app/notas/n1/xml');
    expect(screen.queryByRole('button', { name: 'Emitir NF-e' })).not.toBeInTheDocument();
  });

  it('vendedor edita mas não emite', async () => {
    comPapel('VENDEDOR');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: rascunho });
    renderizar(<DetalheDaNota id="n1" />);

    expect(await screen.findByText(/só um usuário fiscal pode emitir/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Emitir NF-e' })).not.toBeInTheDocument();
  });

  it('mostra o bloqueio fiscal do servidor ao emitir', async () => {
    comPapel('ADMIN');
    const { ApiError } = await import('@/lib/api');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: rascunho });
    vi.mocked(notasApi.emitir).mockRejectedValue(new ApiError(422, 'Complete em Configurações > Fiscal: inscrição estadual.', {}, { codigo: 'FISCAL_EMITENTE_INCOMPLETO' }));
    renderizar(<DetalheDaNota id="n1" />);

    await userEvent.click(await screen.findByRole('button', { name: 'Emitir NF-e' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('inscrição estadual');
  });

  it('faz polling enquanto PROCESSANDO e para ao autorizar', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    comPapel('ADMIN');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: { ...rascunho, status: 'PROCESSANDO', ambiente: 'HOMOLOGACAO', numero: 1, serie: '1' } });
    vi.mocked(notasApi.status)
      .mockResolvedValueOnce({ data: { id: 'n1', status: 'PROCESSANDO', numero: 1, serie: '1', chave: null, cstat: null, motivo: null, mensagem_erro: null, cancelamento_cstat: null, cancelamento_motivo: null } })
      .mockResolvedValue({ data: { id: 'n1', status: 'AUTORIZADA', numero: 1, serie: '1', chave: '3'.repeat(44), cstat: '100', motivo: 'Autorizado', mensagem_erro: null, cancelamento_cstat: null, cancelamento_motivo: null } });
    renderizar(<DetalheDaNota id="n1" />);

    await screen.findByText('Processando');
    await act(async () => { await vi.advanceTimersByTimeAsync(2100); });
    await act(async () => { await vi.advanceTimersByTimeAsync(2100); });
    await waitFor(() => expect(vi.mocked(notasApi.status).mock.calls.length).toBeGreaterThanOrEqual(2));
    vi.useRealTimers();
  });
});

describe('DetalheDaNota cancelamento', () => {
  const justificativa = 'Cancelamento por erro de digitação do valor total';

  it('cancela com confirmação em dois cliques e envia a justificativa', async () => {
    comPapel('ADMIN');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: NOTA });
    vi.mocked(notasApi.cancelar).mockResolvedValue({ data: { id: 'n1', status: 'CANCELANDO', numero: 7, serie: '1', chave: NOTA.chave, cstat: '100', motivo: null, mensagem_erro: null, cancelamento_cstat: null, cancelamento_motivo: null } });
    renderizar(<DetalheDaNota id="n1" />);

    await userEvent.click(await screen.findByRole('button', { name: 'Cancelar NF-e' }));
    expect(screen.getByText(/prazo para cancelar é definido pela SEFAZ/)).toBeInTheDocument();
    await userEvent.type(screen.getByLabelText('Justificativa do cancelamento'), justificativa);
    expect(screen.getByText(`${justificativa.length}/255`)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar cancelamento' }));

    await waitFor(() => expect(notasApi.cancelar).toHaveBeenCalledWith('n1', justificativa));
  });

  it('não deixa confirmar com justificativa curta', async () => {
    comPapel('FISCAL');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: NOTA });
    vi.mocked(notasApi.cancelar).mockClear();
    renderizar(<DetalheDaNota id="n1" />);

    await userEvent.click(await screen.findByRole('button', { name: 'Cancelar NF-e' }));
    await userEvent.type(screen.getByLabelText('Justificativa do cancelamento'), 'curta');
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar cancelamento' }));

    expect(await screen.findByText('A justificativa deve ter de 15 a 255 caracteres.')).toBeInTheDocument();
    expect(notasApi.cancelar).not.toHaveBeenCalled();
  });

  it('vendedor e leitura não veem o botão de cancelar', async () => {
    comPapel('VENDEDOR');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: NOTA });
    renderizar(<DetalheDaNota id="n1" />);
    await screen.findByText(NOTA.chave as string);
    expect(screen.queryByRole('button', { name: 'Cancelar NF-e' })).not.toBeInTheDocument();
  });

  it('mostra o bloqueio do servidor ao pedir o cancelamento', async () => {
    comPapel('ADMIN');
    const { ApiError } = await import('@/lib/api');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: NOTA });
    vi.mocked(notasApi.cancelar).mockRejectedValue(new ApiError(409, 'Só é possível cancelar uma NF-e autorizada.', {}, { codigo: 'CANCELAMENTO_NAO_PERMITIDO' }));
    renderizar(<DetalheDaNota id="n1" />);

    await userEvent.click(await screen.findByRole('button', { name: 'Cancelar NF-e' }));
    await userEvent.type(screen.getByLabelText('Justificativa do cancelamento'), justificativa);
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar cancelamento' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Só é possível cancelar uma NF-e autorizada.');
  });

  it('em CANCELANDO mostra a espera e não oferece cancelar de novo', async () => {
    comPapel('ADMIN');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: { ...NOTA, status: 'CANCELANDO' } });
    vi.mocked(notasApi.status).mockResolvedValue({ data: { id: 'n1', status: 'CANCELANDO', numero: 7, serie: '1', chave: NOTA.chave, cstat: '100', motivo: null, mensagem_erro: null, cancelamento_cstat: null, cancelamento_motivo: null } });
    renderizar(<DetalheDaNota id="n1" />);

    expect(await screen.findByText(/Aguardando a SEFAZ confirmar o cancelamento/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Cancelar NF-e' })).not.toBeInTheDocument();
  });

  it('mostra a recusa do cancelamento com cStat e motivo, e a nota segue autorizada', async () => {
    comPapel('ADMIN');
    vi.mocked(notasApi.buscar).mockResolvedValue({ data: { ...NOTA, cancelamento_cstat: '501', cancelamento_motivo: 'Pedido de Cancelamento intempestivo' } });
    renderizar(<DetalheDaNota id="n1" />);

    expect(await screen.findByRole('alert')).toHaveTextContent('Cancelamento recusado (cStat 501): Pedido de Cancelamento intempestivo');
    expect(screen.getByRole('button', { name: 'Cancelar NF-e' })).toBeInTheDocument();
  });

  it('cancelada mostra protocolo, justificativa e o link do XML do cancelamento', async () => {
    comPapel('LEITURA');
    vi.mocked(notasApi.buscar).mockResolvedValue({
      data: { ...NOTA, status: 'CANCELADA', cancelamento_protocolo: '135260000000555', cancelado_em: '2026-10-02T11:00:00-03:00', cancelamento_justificativa: justificativa },
    });
    renderizar(<DetalheDaNota id="n1" />);

    expect(await screen.findByText('135260000000555')).toBeInTheDocument();
    expect(screen.getByText(justificativa)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Baixar XML do cancelamento' })).toHaveAttribute('href', 'http://api.test/api/app/notas/n1/xml-cancelamento');
  });
});
