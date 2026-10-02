import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { notasApi } from '../api';
import type { Nota } from '../types';
import { ListaDeNotas } from './ListaDeNotas';
import { StatusDaNota } from './StatusDaNota';

vi.mock('../api', () => ({
  QUERY_KEY_NOTAS: ['notas'],
  urlDoXml: (id: string) => `http://api.test/api/app/notas/${id}/xml`,
  notasApi: { listar: vi.fn(), buscar: vi.fn(), status: vi.fn(), criar: vi.fn(), editar: vi.fn(), excluir: vi.fn(), emitir: vi.fn() },
}));

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
