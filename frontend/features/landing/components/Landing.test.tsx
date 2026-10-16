import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { cadastroApi } from '@/features/cadastro/api';
import { contatoApi } from '../api';
import { linkWhatsapp } from '../whatsapp';
import { FormularioDeContato } from './FormularioDeContato';
import { Landing } from './Landing';

vi.mock('../api', () => ({ contatoApi: { desafio: vi.fn(), enviar: vi.fn() } }));
vi.mock('@/features/cadastro/api', () => ({ cadastroApi: { planos: vi.fn() } }));

function renderizar(ui: React.ReactNode) {
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{ui}</QueryClientProvider>);
}

async function preencher(resposta: string) {
  await userEvent.type(await screen.findByLabelText(/Seu nome/), 'Ana Souza');
  await userEvent.type(screen.getByLabelText(/Seu e-mail/), 'ana@exemplo.com');
  await userEvent.type(screen.getByLabelText(/Mensagem/), 'Quero conhecer.');
  await userEvent.type(screen.getByLabelText(/quanto é 3 \+ 4/), resposta);
}

describe('whatsapp', () => {
  it('link aponta para o número comercial com mensagem codificada', () => {
    const url = linkWhatsapp('Olá mundo');
    expect(url).toBe('https://wa.me/5535984297193?text=Ol%C3%A1%20mundo');
  });
});

describe('landing', () => {
  beforeEach(() => {
    vi.mocked(cadastroApi.planos).mockResolvedValue({ data: [] });
    vi.mocked(contatoApi.desafio).mockResolvedValue({ data: { pergunta: '3 + 4', token: 'tok' } });
  });

  it('tem chamada principal, botão de WhatsApp e formulário', async () => {
    renderizar(<Landing />);

    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(/nota fiscal sem susto/i);
    expect(screen.getAllByRole('link', { name: /começar agora|quero começar agora/i }).length).toBeGreaterThan(0);
    const whats = screen.getAllByRole('link', { name: /whatsapp/i });
    expect(whats.length).toBeGreaterThan(0);
    whats.forEach((a) => expect(a).toHaveAttribute('href', expect.stringContaining('wa.me/5535984297193')));
    expect(await screen.findByLabelText(/quanto é 3 \+ 4/)).toBeInTheDocument();
  });
});

describe('formulário de contato', () => {
  beforeEach(() => {
    vi.mocked(contatoApi.desafio).mockResolvedValue({ data: { pergunta: '3 + 4', token: 'tok' } });
  });

  it('envia com o token e a resposta da charada e mostra confirmação', async () => {
    vi.mocked(contatoApi.enviar).mockResolvedValue({ data: { recebido: true } });
    renderizar(<FormularioDeContato />);
    await preencher('7');
    await userEvent.click(screen.getByRole('button', { name: 'Enviar mensagem' }));

    await waitFor(() => expect(contatoApi.enviar).toHaveBeenCalledWith({
      nome: 'Ana Souza', email: 'ana@exemplo.com', mensagem: 'Quero conhecer.', desafio_token: 'tok', desafio_resposta: '7', site: '',
    }));
    expect(await screen.findByText(/Mensagem enviada/)).toBeInTheDocument();
  });

  it('resposta errada mostra o erro e pede outra charada', async () => {
    vi.mocked(contatoApi.enviar).mockRejectedValue(
      new ApiError(422, 'invalido', { desafio_resposta: ['Resposta errada. Confira a conta e tente de novo.'] }),
    );
    renderizar(<FormularioDeContato />);
    await preencher('9');
    await userEvent.click(screen.getByRole('button', { name: 'Enviar mensagem' }));

    expect(await screen.findByText(/Resposta errada/)).toBeInTheDocument();
    await waitFor(() => expect(contatoApi.desafio).toHaveBeenCalledTimes(2));
  });

  it('limite de tentativas orienta a usar o WhatsApp', async () => {
    vi.mocked(contatoApi.enviar).mockRejectedValue(new ApiError(429, 'Too Many Attempts.'));
    renderizar(<FormularioDeContato />);
    await preencher('7');
    await userEvent.click(screen.getByRole('button', { name: 'Enviar mensagem' }));

    expect(await screen.findByText(/Muitas tentativas/)).toBeInTheDocument();
  });
});
