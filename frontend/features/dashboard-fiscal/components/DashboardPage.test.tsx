import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DashboardPage from '@/app/(app)/dashboard/page';
import { useAssinatura } from '@/features/assinatura/hooks/useAssinatura';
import { useUsuario } from '@/features/auth/hooks/useUsuario';

vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: vi.fn() }));
vi.mock('@/features/assinatura/hooks/useAssinatura', () => ({ useAssinatura: vi.fn() }));
vi.mock('../components/PainelFiscal', () => ({ PainelFiscal: () => <div>painel fiscal</div> }));

function comModulos(modulos: string[]) {
  vi.mocked(useAssinatura).mockReturnValue({
    data: { situacao: 'ATIVA', teste_termina_em: null, plano: { nome: 'Plano X', modulos }, consumo: [] },
    isError: false,
  } as never);
}

describe('página do painel', () => {
  beforeEach(() => {
    vi.mocked(useUsuario).mockReturnValue({ data: { nome: 'Ana Souza', papel: 'ADMIN' } } as never);
  });

  it.each([['FISCAL_NFE'], ['FISCAL_NFCE']])('mostra o bloco fiscal quando o plano tem %s', (modulo) => {
    comModulos([modulo]);
    render(<DashboardPage />);

    expect(screen.getByText('painel fiscal')).toBeInTheDocument();
    expect(screen.getByText(/Olá, Ana/)).toBeInTheDocument();
  });

  it('não mostra o bloco fiscal quando o plano não tem módulo fiscal', () => {
    comModulos(['CRM']);
    render(<DashboardPage />);

    expect(screen.queryByText('painel fiscal')).not.toBeInTheDocument();
    expect(screen.getByText(/Seu plano: Plano X/)).toBeInTheDocument();
  });

  it('não mostra o bloco fiscal enquanto a assinatura carrega', () => {
    vi.mocked(useAssinatura).mockReturnValue({ data: undefined, isError: false } as never);
    render(<DashboardPage />);

    expect(screen.queryByText('painel fiscal')).not.toBeInTheDocument();
  });
});
