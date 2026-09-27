import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { adminApi } from '../api';
import { EditarPlano } from './EditarPlano';
import { PainelMetricas } from './PainelMetricas';

vi.mock('../api', () => ({ adminApi: { metricas: vi.fn(), plano: vi.fn() } }));

function renderizar(ui: React.ReactNode) {
  render(<QueryClientProvider client={new QueryClient()}>{ui}</QueryClientProvider>);
}

describe('área da plataforma', () => {
  it('painel mostra MRR e contagem por situação', async () => {
    vi.mocked(adminApi.metricas).mockResolvedValue({
      data: {
        por_situacao: { PENDENTE: 1, TESTE: 2, ATIVA: 3, INADIMPLENTE: 0, SUSPENSA: 4, CANCELADA: 0 },
        novas_30_dias: 5,
        mrr_centavos: 14900,
      },
    });
    renderizar(<PainelMetricas />);

    expect(await screen.findByText(/149,00/)).toBeInTheDocument();
    expect(screen.getByRole('group', { name: 'Suspensa' })).toHaveTextContent('4');
  });

  it('edição avisa quantas empresas usam o plano', async () => {
    vi.mocked(adminApi.plano).mockResolvedValue({
      data: {
        id: 'p1', nome: 'Essencial', descricao: null, preco_mensal_centavos: 9900, preco_anual_centavos: null, dias_teste: 0,
        politica_excedente: 'BLOQUEAR', preco_documento_excedente_centavos: null, modulos: [],
        limites: { USUARIOS: 3, CLIENTES: 0, PRODUTOS: 0, SERVICOS: 0, DOCUMENTOS_MES: 0, API_REQUISICOES_MIN: 0 },
        ativo: true, visivel: true, ordem: 0, empresas: 2,
      },
    });
    renderizar(<EditarPlano id="p1" />);

    expect(await screen.findByText('2 empresas usam este plano. As alterações valem para todas.')).toBeInTheDocument();
  });
});
