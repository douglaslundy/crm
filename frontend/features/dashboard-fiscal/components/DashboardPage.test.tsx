import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DashboardPage from '@/app/(app)/dashboard/page';
import { useAssinatura } from '@/features/assinatura/hooks/useAssinatura';
import { useUsuario } from '@/features/auth/hooks/useUsuario';

vi.mock('@/features/auth/hooks/useUsuario', () => ({ useUsuario: vi.fn() }));
vi.mock('@/features/assinatura/hooks/useAssinatura', () => ({ useAssinatura: vi.fn() }));
vi.mock('../components/PainelFiscal', () => ({ PainelFiscal: () => <div>painel fiscal</div> }));
vi.mock('@/features/crm/components/BlocoCrmNoPainel', () => ({ BlocoCrmNoPainel: () => <div>bloco crm</div> }));

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

  it('mostra o bloco do CRM quando o plano tem o módulo CRM', () => {
    comModulos(['CRM']);
    render(<DashboardPage />);

    expect(screen.getByText('bloco crm')).toBeInTheDocument();
  });

  it('não mostra o bloco do CRM sem o módulo ou para o papel Fiscal', () => {
    comModulos(['FISCAL_NFE']);
    const { unmount } = render(<DashboardPage />);
    expect(screen.queryByText('bloco crm')).not.toBeInTheDocument();
    unmount();

    vi.mocked(useUsuario).mockReturnValue({ data: { nome: 'Ana Souza', papel: 'FISCAL' } } as never);
    comModulos(['CRM']);
    render(<DashboardPage />);
    expect(screen.queryByText('bloco crm')).not.toBeInTheDocument();
  });

  it('não mostra o bloco fiscal enquanto a assinatura carrega', () => {
    vi.mocked(useAssinatura).mockReturnValue({ data: undefined, isError: false } as never);
    render(<DashboardPage />);

    expect(screen.queryByText('painel fiscal')).not.toBeInTheDocument();
  });
});
