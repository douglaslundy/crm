import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Papel } from '@/features/auth/types';
import { ApiError } from '@/lib/api';
import { chavesApi } from '../api';
import type { ChaveCriada, ChaveDeApi } from '../types';
import { ChavesDeApi } from './ChavesDeApi';

const papel = vi.hoisted(() => ({ valor: 'ADMIN' as Papel }));

vi.mock('@/features/auth/hooks/useUsuario', () => ({
  useUsuario: () => ({ data: { id: 'u', nome: 'Admin', email: 'a@x.com', papel: papel.valor, tenant: null } }),
}));
vi.mock('../api', () => ({
  QUERY_KEY_CHAVES_API: ['chaves-api'],
  chavesApi: { listar: vi.fn(), criar: vi.fn(), revogar: vi.fn() },
}));

const TEXTO = 'live_AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd';

const ativa: ChaveDeApi = {
  id: 'k1', nome: 'ERP da loja', ambiente: 'LIVE', prefixo: 'live_AbCdEfG', ultimos4: 'abcd',
  usuario: { id: 'u', nome: 'Admin' }, ultimo_uso_em: '2026-10-08T12:00:00+00:00', revogada_em: null, criada_em: '2026-10-01T10:00:00+00:00',
};
const revogada: ChaveDeApi = { ...ativa, id: 'k2', nome: 'Antiga', ambiente: 'TEST', prefixo: 'test_ZzZzZzZ', ultimos4: 'wxyz', ultimo_uso_em: null, revogada_em: '2026-10-05T09:00:00+00:00' };

function renderizar() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}><ChavesDeApi /></QueryClientProvider>);
}

describe('chaves de API', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    papel.valor = 'ADMIN';
    vi.mocked(chavesApi.listar).mockResolvedValue({ data: [ativa, revogada] });
  });

  it('lista nome, ambiente, prefixo…últimos 4, último uso e a marca de revogada', async () => {
    renderizar();

    const linhaAtiva = (await screen.findByText('ERP da loja')).closest('li') as HTMLElement;
    expect(within(linhaAtiva).getByText(/live_AbCdEfG…abcd/)).toBeInTheDocument();
    expect(within(linhaAtiva).getByText(/Produção/)).toBeInTheDocument();
    expect(within(linhaAtiva).getByText(/Último uso: 08\/10\/2026/)).toBeInTheDocument();

    const linhaRevogada = screen.getByText('Antiga').closest('li') as HTMLElement;
    expect(within(linhaRevogada).getByText('Revogada')).toBeInTheDocument();
    expect(within(linhaRevogada).getByText(/Nunca usada/)).toBeInTheDocument();
    expect(within(linhaRevogada).queryByRole('button', { name: /Revogar/ })).not.toBeInTheDocument();
  });

  it('criar mostra a chave inteira uma vez, com aviso e botão de copiar; ao fechar ela some', async () => {
    const usuario = userEvent.setup();
    const copiar = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue();
    const criada: ChaveCriada = { ...ativa, id: 'k3', nome: 'Integração nova', ambiente: 'TEST', chave: TEXTO };
    vi.mocked(chavesApi.criar).mockResolvedValue({ data: criada });
    renderizar();

    await usuario.type(await screen.findByLabelText('Nome'), 'Integração nova');
    await usuario.selectOptions(screen.getByLabelText('Ambiente'), 'TEST');
    await usuario.click(screen.getByRole('button', { name: 'Criar chave' }));

    await waitFor(() => expect(chavesApi.criar).toHaveBeenCalledWith({ nome: 'Integração nova', ambiente: 'TEST' }));
    expect(await screen.findByText(TEXTO)).toBeInTheDocument();
    expect(screen.getByText(/Copie agora: ela não será mostrada de novo/)).toBeInTheDocument();

    await usuario.click(screen.getByRole('button', { name: 'Copiar chave' }));
    expect(copiar).toHaveBeenCalledWith(TEXTO);

    await usuario.click(screen.getByRole('button', { name: 'Fechar aviso' }));
    expect(screen.queryByText(TEXTO)).not.toBeInTheDocument();
    expect(document.body.textContent).not.toContain(TEXTO);
  });

  it('a chave inteira não fica em localStorage nem no cache', async () => {
    const usuario = userEvent.setup();
    vi.mocked(chavesApi.criar).mockResolvedValue({ data: { ...ativa, chave: TEXTO } });
    renderizar();

    await usuario.type(await screen.findByLabelText('Nome'), 'ERP');
    await usuario.click(screen.getByRole('button', { name: 'Criar chave' }));
    await screen.findByText(TEXTO);

    expect(JSON.stringify({ ...localStorage })).not.toContain(TEXTO);
    expect(JSON.stringify({ ...sessionStorage })).not.toContain(TEXTO);
  });

  it('o nome da chave é obrigatório', async () => {
    renderizar();

    await userEvent.click(await screen.findByRole('button', { name: 'Criar chave' }));

    expect(await screen.findByText('Informe um nome para a chave.')).toBeInTheDocument();
    expect(chavesApi.criar).not.toHaveBeenCalled();
  });

  it('revogar pede confirmação e só então chama a API', async () => {
    const usuario = userEvent.setup();
    vi.mocked(chavesApi.revogar).mockResolvedValue({ data: { ...ativa, revogada_em: '2026-10-08T13:00:00+00:00' } });
    renderizar();

    await usuario.click(await screen.findByRole('button', { name: 'Revogar ERP da loja' }));
    expect(chavesApi.revogar).not.toHaveBeenCalled();

    await usuario.click(screen.getByRole('button', { name: 'Cancelar' }));
    expect(chavesApi.revogar).not.toHaveBeenCalled();

    await usuario.click(screen.getByRole('button', { name: 'Revogar ERP da loja' }));
    await usuario.click(screen.getByRole('button', { name: 'Confirmar revogação' }));
    await waitFor(() => expect(chavesApi.revogar).toHaveBeenCalledWith('k1'));
  });

  it('erro da API aparece em alerta (limite de 20 chaves)', async () => {
    vi.mocked(chavesApi.criar).mockRejectedValue(new ApiError(422, 'A empresa já tem 20 chaves de API ativas. Revogue uma antes de criar outra.', {}, { codigo: 'API_CHAVES_LIMITE_ATINGIDO' }));
    renderizar();

    await userEvent.type(await screen.findByLabelText('Nome'), 'ERP');
    await userEvent.click(screen.getByRole('button', { name: 'Criar chave' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('20 chaves de API ativas');
  });

  it('erro de módulo fora do plano aparece em alerta', async () => {
    vi.mocked(chavesApi.criar).mockRejectedValue(new ApiError(403, 'O módulo API não faz parte do seu plano.', {}, { codigo: 'MODULO_NAO_CONTRATADO' }));
    renderizar();

    await userEvent.type(await screen.findByLabelText('Nome'), 'ERP');
    await userEvent.click(screen.getByRole('button', { name: 'Criar chave' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('não faz parte do seu plano');
  });

  it('papel sem permissão vê só o aviso e nenhuma chamada é feita', async () => {
    papel.valor = 'VENDEDOR';
    renderizar();

    expect(await screen.findByRole('alert')).toHaveTextContent('Você não tem permissão');
    expect(chavesApi.listar).not.toHaveBeenCalled();
    expect(screen.queryByRole('button', { name: 'Criar chave' })).not.toBeInTheDocument();
  });
});
