import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { cadastroApi } from '../api';
import { CadastroForm } from './CadastroForm';
import { planoDeTeste } from './fixtures';

const push = vi.fn();
vi.mock('next/navigation', () => ({ useRouter: () => ({ push }) }));
vi.mock('../api', () => ({ cadastroApi: { planos: vi.fn(), consultarCnpj: vi.fn(), cadastrar: vi.fn() } }));

const usuario = {
  id: 'u1', nome: 'Ana Souza', email: 'ana@paobom.com', papel: 'PROPRIETARIO' as const,
  tenant: { id: 't', razao_social: 'Padaria Pão Bom Ltda', nome_fantasia: 'Pão Bom', cnpj: '11222333000181', situacao: 'TESTE' as const, teste_termina_em: '2026-10-15' },
};

function renderizar() {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false }, queries: { retry: false } } });
  render(<QueryClientProvider client={client}><CadastroForm planoId="p1" /></QueryClientProvider>);
}

async function preencherResponsavel() {
  await userEvent.type(screen.getByLabelText('Seu nome'), 'Ana Souza');
  await userEvent.type(screen.getByLabelText('E-mail'), 'ana@paobom.com');
  await userEvent.type(screen.getByLabelText('Senha'), 'Senha123');
  await userEvent.type(screen.getByLabelText('Confirmar senha'), 'Senha123');
  await userEvent.click(screen.getByLabelText(/Li e aceito os termos de uso/));
}

describe('CadastroForm', () => {
  beforeEach(() => {
    push.mockClear();
    vi.mocked(cadastroApi.planos).mockResolvedValue({ data: [planoDeTeste] });
    vi.mocked(cadastroApi.consultarCnpj).mockReset();
    vi.mocked(cadastroApi.cadastrar).mockReset();
  });

  it('busca o CNPJ, avança para o responsável e cria a conta', async () => {
    vi.mocked(cadastroApi.consultarCnpj).mockResolvedValue({ data: { razao_social: 'Padaria Pão Bom Ltda', nome_fantasia: 'Pão Bom' } });
    vi.mocked(cadastroApi.cadastrar).mockResolvedValue({ data: usuario });
    renderizar();

    expect(await screen.findByText(/Plano escolhido:/)).toHaveTextContent('Plano escolhido: Essencial');
    await userEvent.type(screen.getByLabelText('CNPJ'), '11222333000181');

    await waitFor(() => expect(screen.getByLabelText('Razão social')).toHaveValue('Padaria Pão Bom Ltda'));
    expect(cadastroApi.consultarCnpj).toHaveBeenCalledWith('11222333000181');
    expect(screen.getByLabelText('CNPJ')).toHaveValue('11.222.333/0001-81');
    expect(screen.getByLabelText('Nome fantasia')).toHaveValue('Pão Bom');

    await userEvent.click(screen.getByRole('button', { name: 'Continuar' }));
    await preencherResponsavel();
    await userEvent.click(screen.getByRole('button', { name: 'Criar conta' }));

    await waitFor(() => expect(cadastroApi.cadastrar).toHaveBeenCalledWith({
      cnpj: '11222333000181', razao_social: 'Padaria Pão Bom Ltda', nome_fantasia: 'Pão Bom',
      responsavel_nome: 'Ana Souza', email: 'ana@paobom.com', password: 'Senha123', password_confirmation: 'Senha123',
      aceite_termos: true, plano_id: 'p1',
    }));
    await waitFor(() => expect(push).toHaveBeenCalledWith('/dashboard'));
  });

  it('com a BrasilAPI fora, avisa e deixa preencher à mão', async () => {
    vi.mocked(cadastroApi.consultarCnpj).mockRejectedValue(new ApiError(503, 'Indisponível'));
    renderizar();

    await userEvent.type(screen.getByLabelText('CNPJ'), '11222333000181');
    expect(await screen.findByText('Não foi possível buscar os dados agora. Preencha manualmente.')).toBeInTheDocument();

    await userEvent.type(screen.getByLabelText('Razão social'), 'Padaria Manual Ltda');
    await userEvent.click(screen.getByRole('button', { name: 'Continuar' }));

    expect(await screen.findByLabelText('Seu nome')).toBeInTheDocument();
  });

  it('CNPJ inválido não avança nem consulta', async () => {
    renderizar();

    await userEvent.type(screen.getByLabelText('CNPJ'), '11222333000182');
    await userEvent.type(screen.getByLabelText('Razão social'), 'X');
    await userEvent.click(screen.getByRole('button', { name: 'Continuar' }));

    expect(await screen.findByText('Informe um CNPJ válido.')).toBeInTheDocument();
    expect(cadastroApi.consultarCnpj).not.toHaveBeenCalled();
    expect(screen.queryByLabelText('Seu nome')).not.toBeInTheDocument();
  });

  it('CNPJ já cadastrado volta para a etapa da empresa com a mensagem', async () => {
    vi.mocked(cadastroApi.consultarCnpj).mockRejectedValue(new ApiError(404, 'Não encontrado'));
    vi.mocked(cadastroApi.cadastrar).mockRejectedValue(
      new ApiError(422, 'Dados inválidos.', { cnpj: ['Este CNPJ já possui conta. Entre ou recupere a senha.'] }),
    );
    renderizar();

    await userEvent.type(screen.getByLabelText('CNPJ'), '11222333000181');
    await userEvent.type(screen.getByLabelText('Razão social'), 'Padaria');
    await userEvent.click(screen.getByRole('button', { name: 'Continuar' }));
    await preencherResponsavel();
    await userEvent.click(screen.getByRole('button', { name: 'Criar conta' }));

    expect(await screen.findByText('Este CNPJ já possui conta. Entre ou recupere a senha.')).toBeInTheDocument();
    expect(screen.getByLabelText('CNPJ')).toBeInTheDocument();
  });
});
