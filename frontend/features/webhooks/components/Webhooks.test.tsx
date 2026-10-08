import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Papel } from '@/features/auth/types';
import { ApiError } from '@/lib/api';
import { webhooksApi } from '../api';
import type { Entrega, Webhook, WebhookCriado } from '../types';
import { Webhooks } from './Webhooks';

const papel = vi.hoisted(() => ({ valor: 'ADMIN' as Papel }));

vi.mock('@/features/auth/hooks/useUsuario', () => ({
  useUsuario: () => ({ data: { id: 'u', nome: 'Admin', email: 'a@x.com', papel: papel.valor, tenant: null } }),
}));
vi.mock('../api', () => ({
  QUERY_KEY_WEBHOOKS: ['webhooks'],
  queryKeyEntregas: (id: string) => ['webhooks', id, 'entregas'],
  webhooksApi: { listar: vi.fn(), criar: vi.fn(), atualizar: vi.fn(), excluir: vi.fn(), entregas: vi.fn(), testar: vi.fn(), reenviar: vi.fn() },
}));

const SEGREDO = 'whsec_AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd';

const ativo: Webhook = {
  id: 'w1', url: 'https://erp.exemplo.com/hook', descricao: 'ERP da loja', eventos: ['nota.autorizada', 'nota.cancelada'], ambiente: 'PRODUCAO',
  ativo: true, desativado_em: null, motivo_desativacao: null, segredo_final: 'abcd', criado_em: '2026-10-01T10:00:00-03:00',
};
const desativado: Webhook = {
  ...ativo, id: 'w2', url: 'https://velho.exemplo.com/hook', descricao: null, eventos: [], ambiente: 'HOMOLOGACAO', ativo: false,
  desativado_em: '2026-10-05T09:00:00-03:00', motivo_desativacao: '10 entregas seguidas sem sucesso',
};
const entregaFalhou: Entrega = {
  id: 'e1', webhook_id: 'w1', nota_id: 'n1', evento: 'nota.autorizada', evento_id: 'ev1', estado: 'FALHOU', tentativas: 7, proxima_tentativa_em: null,
  entregue_em: null, criada_em: '2026-10-08T10:00:00-03:00',
  tentativas_log: [{ numero: 7, status_http: 500, duracao_ms: 120, erro: null, resposta: 'Erro interno do servidor do ERP', em: '2026-10-08T10:20:00-03:00' }],
};
const entregaPendente: Entrega = {
  ...entregaFalhou, id: 'e2', evento: 'nota.cancelada', estado: 'PENDENTE', tentativas: 1, proxima_tentativa_em: '2026-10-08T11:00:00-03:00',
  tentativas_log: [{ numero: 1, status_http: null, duracao_ms: 10000, erro: 'Operation timed out', resposta: null, em: '2026-10-08T10:59:00-03:00' }],
};

function renderizar() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}><Webhooks /></QueryClientProvider>);
}

describe('webhooks', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    papel.valor = 'ADMIN';
    vi.mocked(webhooksApi.listar).mockResolvedValue({ data: [ativo, desativado] });
    vi.mocked(webhooksApi.entregas).mockResolvedValue({ data: [entregaFalhou, entregaPendente] });
  });

  it('lista URL, ambiente, eventos, estado e o motivo da desativação', async () => {
    renderizar();

    const linhaAtiva = (await screen.findByText('https://erp.exemplo.com/hook')).closest('li') as HTMLElement;
    expect(within(linhaAtiva).getByText(/ERP da loja/)).toBeInTheDocument();
    expect(within(linhaAtiva).getByText(/Produção/)).toBeInTheDocument();
    expect(within(linhaAtiva).getByText(/Nota autorizada, Nota cancelada/)).toBeInTheDocument();
    expect(within(linhaAtiva).getByText('Ativo')).toBeInTheDocument();

    const linhaDesativada = screen.getByText('https://velho.exemplo.com/hook').closest('li') as HTMLElement;
    expect(within(linhaDesativada).getByText('Desativado')).toBeInTheDocument();
    expect(within(linhaDesativada).getByText(/10 entregas seguidas sem sucesso/)).toBeInTheDocument();
    expect(within(linhaDesativada).getByText(/Todos os eventos de nota/)).toBeInTheDocument();
    expect(within(linhaDesativada).getByText(/Homologação/)).toBeInTheDocument();
  });

  it('criar mostra o segredo uma vez, com aviso e Copiar; ao fechar ele some e não vai a storage', async () => {
    const usuario = userEvent.setup();
    const copiar = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue();
    const criado: WebhookCriado = { ...ativo, id: 'w3', url: 'https://novo.exemplo.com/h', segredo: SEGREDO };
    vi.mocked(webhooksApi.criar).mockResolvedValue({ data: criado });
    renderizar();

    await usuario.type(await screen.findByLabelText('URL'), 'https://novo.exemplo.com/h');
    await usuario.selectOptions(screen.getByLabelText('Ambiente'), 'PRODUCAO');
    await usuario.click(screen.getByLabelText('Nota rejeitada'));
    await usuario.click(screen.getByRole('button', { name: 'Criar webhook' }));

    await waitFor(() =>
      expect(webhooksApi.criar).toHaveBeenCalledWith({ url: 'https://novo.exemplo.com/h', descricao: '', ambiente: 'PRODUCAO', eventos: ['nota.rejeitada'] }),
    );
    expect(await screen.findByText(SEGREDO)).toBeInTheDocument();
    expect(screen.getByText(/Copie agora: ele não será mostrado de novo/)).toBeInTheDocument();
    expect(JSON.stringify({ ...localStorage })).not.toContain(SEGREDO);
    expect(JSON.stringify({ ...sessionStorage })).not.toContain(SEGREDO);

    await usuario.click(screen.getByRole('button', { name: 'Copiar segredo' }));
    expect(copiar).toHaveBeenCalledWith(SEGREDO);

    await usuario.click(screen.getByRole('button', { name: 'Fechar aviso' }));
    expect(document.body.textContent).not.toContain(SEGREDO);
  });

  it('a URL é obrigatória', async () => {
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: 'Criar webhook' }));

    expect(await screen.findByText('Informe a URL do webhook.')).toBeInTheDocument();
    expect(webhooksApi.criar).not.toHaveBeenCalled();
  });

  it('ativar e desativar chamam a API', async () => {
    const usuario = userEvent.setup();
    vi.mocked(webhooksApi.atualizar).mockResolvedValue({ data: ativo });
    renderizar();

    await usuario.click(await screen.findByRole('button', { name: 'Desativar https://erp.exemplo.com/hook' }));
    await waitFor(() => expect(webhooksApi.atualizar).toHaveBeenCalledWith('w1', { ativo: false }));

    await usuario.click(screen.getByRole('button', { name: 'Ativar https://velho.exemplo.com/hook' }));
    await waitFor(() => expect(webhooksApi.atualizar).toHaveBeenCalledWith('w2', { ativo: true }));
  });

  it('excluir pede confirmação e só então chama a API', async () => {
    const usuario = userEvent.setup();
    vi.mocked(webhooksApi.excluir).mockResolvedValue(undefined);
    renderizar();

    await usuario.click(await screen.findByRole('button', { name: 'Excluir https://erp.exemplo.com/hook' }));
    expect(webhooksApi.excluir).not.toHaveBeenCalled();
    await usuario.click(screen.getByRole('button', { name: 'Cancelar' }));
    expect(webhooksApi.excluir).not.toHaveBeenCalled();

    await usuario.click(screen.getByRole('button', { name: 'Excluir https://erp.exemplo.com/hook' }));
    await usuario.click(screen.getByRole('button', { name: 'Confirmar exclusão' }));
    await waitFor(() => expect(webhooksApi.excluir).toHaveBeenCalledWith('w1'));
  });

  it('Enviar teste chama a API e mostra o resultado', async () => {
    const usuario = userEvent.setup();
    vi.mocked(webhooksApi.testar).mockResolvedValue({
      data: { ...entregaFalhou, id: 'e9', evento: 'webhook.teste', estado: 'ENTREGUE', tentativas: 1, tentativas_log: [{ numero: 1, status_http: 200, duracao_ms: 80, erro: null, resposta: 'ok', em: '2026-10-08T10:00:00-03:00' }] },
    });
    renderizar();

    await usuario.click(await screen.findByRole('button', { name: 'Enviar teste para https://erp.exemplo.com/hook' }));

    await waitFor(() => expect(webhooksApi.testar).toHaveBeenCalledWith('w1'));
    expect(await screen.findByText(/Teste entregue \(HTTP 200\)/)).toBeInTheDocument();
  });

  it('teste que falha aparece em alerta com o motivo', async () => {
    vi.mocked(webhooksApi.testar).mockResolvedValue({
      data: { ...entregaFalhou, evento: 'webhook.teste', estado: 'FALHOU', tentativas: 1, tentativas_log: [{ numero: 1, status_http: 503, duracao_ms: 80, erro: null, resposta: null, em: '2026-10-08T10:00:00-03:00' }] },
    });
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: 'Enviar teste para https://erp.exemplo.com/hook' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('O teste falhou (HTTP 503)');
  });

  it('abrir as entregas lista evento, estado, tentativas e próxima tentativa; detalhes mostram o log', async () => {
    const usuario = userEvent.setup();
    renderizar();

    await usuario.click(await screen.findByRole('button', { name: 'Entregas de https://erp.exemplo.com/hook' }));

    const falhou = (await screen.findByText('Falhou')).closest('li') as HTMLElement;
    expect(within(falhou).getByText('Nota autorizada')).toBeInTheDocument();
    expect(within(falhou).getByText(/7 tentativas/)).toBeInTheDocument();

    const pendente = screen.getByText('Pendente').closest('li') as HTMLElement;
    expect(within(pendente).getByText(/Próxima tentativa: 08\/10\/2026 11:00/)).toBeInTheDocument();

    await usuario.click(within(falhou).getByRole('button', { name: 'Ver tentativas' }));
    expect(within(falhou).getByText(/HTTP 500/)).toBeInTheDocument();
    expect(within(falhou).getByText(/Erro interno do servidor do ERP/)).toBeInTheDocument();

    await usuario.click(within(pendente).getByRole('button', { name: 'Ver tentativas' }));
    expect(within(pendente).getByText(/Operation timed out/)).toBeInTheDocument();
  });

  it('Reenviar chama a API e a entrega pendente não oferece o botão', async () => {
    const usuario = userEvent.setup();
    vi.mocked(webhooksApi.reenviar).mockResolvedValue({ data: { ...entregaFalhou, estado: 'ENTREGUE' } });
    renderizar();
    await usuario.click(await screen.findByRole('button', { name: 'Entregas de https://erp.exemplo.com/hook' }));

    const botoes = await screen.findAllByRole('button', { name: /Reenviar/ });
    expect(botoes).toHaveLength(1);
    await usuario.click(botoes[0]);

    await waitFor(() => expect(webhooksApi.reenviar).toHaveBeenCalledWith('e1'));
  });

  it('erro de URL inválida aparece em alerta', async () => {
    vi.mocked(webhooksApi.criar).mockRejectedValue(new ApiError(422, 'A URL do webhook não pode ser usada: a URL precisa usar https.', {}, { codigo: 'WEBHOOK_URL_INVALIDA' }));
    renderizar();

    await userEvent.type(await screen.findByLabelText('URL'), 'http://x.com');
    await userEvent.click(screen.getByRole('button', { name: 'Criar webhook' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('precisa usar https');
  });

  it('erro de limite e de módulo fora do plano aparecem em alerta', async () => {
    vi.mocked(webhooksApi.criar).mockRejectedValueOnce(new ApiError(422, 'A empresa já tem 5 webhooks. Exclua um antes de criar outro.', {}, { codigo: 'WEBHOOK_LIMITE_ATINGIDO' }));
    renderizar();

    await userEvent.type(await screen.findByLabelText('URL'), 'https://x.com/h');
    await userEvent.click(screen.getByRole('button', { name: 'Criar webhook' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('já tem 5 webhooks');

    vi.mocked(webhooksApi.criar).mockRejectedValueOnce(new ApiError(403, 'O módulo API não faz parte do seu plano.', {}, { codigo: 'MODULO_NAO_CONTRATADO' }));
    await userEvent.click(screen.getByRole('button', { name: 'Criar webhook' }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('não faz parte do seu plano'));
  });

  it('papel sem permissão vê só o aviso e nenhuma chamada é feita', async () => {
    papel.valor = 'VENDEDOR';
    renderizar();

    expect(await screen.findByRole('alert')).toHaveTextContent('Você não tem permissão');
    expect(webhooksApi.listar).not.toHaveBeenCalled();
    expect(screen.queryByRole('button', { name: 'Criar webhook' })).not.toBeInTheDocument();
  });
});
