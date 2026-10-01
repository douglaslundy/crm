import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { clientesApi } from '../api';
import type { Cliente } from '../types';
import { ClienteForm } from './ClienteForm';
import { ListaDeClientes } from './ListaDeClientes';

vi.mock('../api', () => ({
  QUERY_KEY_CLIENTES: ['clientes'],
  clientesApi: { listar: vi.fn(), criar: vi.fn(), editar: vi.fn(), converterEmCliente: vi.fn() },
  contatosApi: { criar: vi.fn(), editar: vi.fn() },
  consultarCep: vi.fn(),
  paraPayload: (d: { nome: string; cpf_cnpj?: string }) => ({ ...d, cpf_cnpj: d.cpf_cnpj || null }),
}));

function cliente(estagio: 'LEAD' | 'CLIENTE'): Cliente {
  return {
    id: '1', tipo: 'PF', nome: 'Maria', cpf_cnpj: null, inscricao_estadual: null, ie_isento: false, email: null,
    telefone: null, logradouro: null, numero: null, bairro: null, cidade: null, uf: null, cep: null,
    codigo_ibge: null, tags: [], origem: null, estagio,
  };
}

function renderizar(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('clientes', () => {
  it('cria lead PF só com o nome', async () => {
    vi.mocked(clientesApi.criar).mockResolvedValue({ data: cliente('LEAD') });
    renderizar(<ClienteForm />);

    await userEvent.type(screen.getByLabelText('Nome'), 'Maria');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar cliente' }));

    await waitFor(() => expect(clientesApi.criar).toHaveBeenCalledWith(expect.objectContaining({ nome: 'Maria', cpf_cnpj: null })));
  });

  it('CPF inválido mostra o erro devolvido pela API', async () => {
    vi.mocked(clientesApi.criar).mockRejectedValue(new ApiError(422, 'Dados inválidos.', { cpf_cnpj: ['Informe um CPF válido.'] }));
    renderizar(<ClienteForm />);

    await userEvent.type(screen.getByLabelText('Nome'), 'Maria');
    await userEvent.type(screen.getByLabelText('CPF'), '111.111.111-11');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar cliente' }));

    expect(await screen.findByText('Informe um CPF válido.')).toBeInTheDocument();
  });

  it('converte lead em cliente', async () => {
    vi.mocked(clientesApi.converterEmCliente).mockResolvedValue({ data: cliente('CLIENTE') });
    vi.mocked(clientesApi.listar).mockResolvedValue({ data: [cliente('LEAD')] });
    renderizar(<ListaDeClientes podeEscrever />);

    await userEvent.click(await screen.findByRole('button', { name: 'Converter Maria em cliente' }));
    await waitFor(() => expect(clientesApi.converterEmCliente).toHaveBeenCalledWith('1'));
  });
});
