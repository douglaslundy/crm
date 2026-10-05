import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useDashboardFiscal } from '../hooks/useDashboardFiscal';
import type { DashboardFiscal } from '../types';
import { PainelFiscal } from './PainelFiscal';

vi.mock('../hooks/useDashboardFiscal', () => ({ useDashboardFiscal: vi.fn() }));
vi.mock('recharts', () => ({
  ResponsiveContainer: ({ children }: { children: ReactNode }) => <div>{children}</div>,
  BarChart: ({ children }: { children: ReactNode }) => <div data-testid="grafico">{children}</div>,
  Bar: ({ children }: { children: ReactNode }) => <div>{children}</div>,
  Cell: () => null,
  XAxis: () => null,
  Tooltip: () => null,
}));

const MESES = Array.from({ length: 12 }, (_, i) => ({ mes: `2026-${String(i + 1).padStart(2, '0')}`, valor_centavos: 0, quantidade: 0 }));

function dados(sobrescrever: Partial<DashboardFiscal> = {}): DashboardFiscal {
  return {
    ambiente: 'HOMOLOGACAO',
    certificado: { status: 'VALIDO', validade: '2027-01-10', dias_restantes: 90 },
    mes: {
      periodo: '2026-10',
      notas: [
        { modelo: 'NFE', status: 'AUTORIZADA', quantidade: 3 },
        { modelo: 'NFCE', status: 'REJEITADA', quantidade: 1 },
      ],
      valor_emitido_centavos: 123456,
    },
    faturamento_12_meses: MESES.map((m) => (m.mes === '2026-09' ? { ...m, valor_centavos: 50000, quantidade: 2 } : m)),
    rejeicoes: {
      quantidade: 2,
      recentes: [
        { id: 'n1', modelo: 'NFE', numero: 7, cstat: '232', motivo: 'IE do destinatário não informada', mensagem_erro: null, status: 'REJEITADA', created_at: '2026-10-02T10:00:00Z' },
        { id: 'n2', modelo: 'NFCE', numero: 8, cstat: null, motivo: null, mensagem_erro: 'Falha técnica ao emitir: timeout', status: 'ERRO', created_at: '2026-10-03T10:00:00Z' },
      ],
    },
    ...sobrescrever,
  };
}

function comDados(d: DashboardFiscal) {
  vi.mocked(useDashboardFiscal).mockReturnValue({ data: d, isPending: false, isError: false } as never);
}

describe('PainelFiscal', () => {
  beforeEach(() => comDados(dados()));

  it('mostra o valor emitido e o total de notas do mês', () => {
    render(<PainelFiscal />);

    expect(screen.getByText(/R\$\s*1\.234,56/)).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Notas do mês' })).toHaveTextContent('4');
  });

  it('lista as notas do mês por modelo e status em texto', () => {
    render(<PainelFiscal />);
    const lista = screen.getByRole('list', { name: 'Notas do mês por status' });

    expect(within(lista).getByText('Autorizada')).toBeInTheDocument();
    expect(within(lista).getByText('Rejeitada')).toBeInTheDocument();
    expect(within(lista).getByText(/NF-e/)).toBeInTheDocument();
    expect(within(lista).getByText(/NFC-e/)).toBeInTheDocument();
  });

  it('rejeições que pedem ação levam à nota', () => {
    render(<PainelFiscal />);

    const link = screen.getByRole('link', { name: /NF-e nº 7/ });
    expect(link).toHaveAttribute('href', '/notas/n1');
    expect(screen.getByText(/IE do destinatário não informada/)).toBeInTheDocument();
    expect(screen.getByText(/Falha técnica ao emitir: timeout/)).toBeInTheDocument();
  });

  it('sem rejeições não mostra a lista', () => {
    comDados(dados({ rejeicoes: { quantidade: 0, recentes: [] } }));
    render(<PainelFiscal />);

    expect(screen.queryByRole('link', { name: /nº 7/ })).not.toBeInTheDocument();
    expect(screen.getByText('Nenhuma rejeição pendente.')).toBeInTheDocument();
  });

  it.each([
    [90, 'ok', /90 dias/],
    [10, 'alerta', /10 dias/],
    [-3, 'vencido', /vencido/i],
    [null, 'sem-validade', /sem validade/i],
  ] as const)('certificado com %s dias fica na faixa %s', (dias, faixa, texto) => {
    comDados(dados({ certificado: { status: 'VALIDO', validade: dias === null ? null : '2027-01-10', dias_restantes: dias } }));
    render(<PainelFiscal />);

    const cartao = screen.getByRole('region', { name: 'Certificado digital' });
    expect(cartao).toHaveAttribute('data-faixa', faixa);
    expect(cartao).toHaveTextContent(texto);
  });

  it('ambiente de produção aparece como alerta; homologação como aviso informativo', () => {
    comDados(dados({ ambiente: 'PRODUCAO' }));
    const { unmount } = render(<PainelFiscal />);
    expect(screen.getByRole('alert')).toHaveTextContent('Ambiente de PRODUÇÃO');
    unmount();

    comDados(dados({ ambiente: 'HOMOLOGACAO' }));
    render(<PainelFiscal />);
    expect(screen.getByRole('status')).toHaveTextContent('Ambiente de HOMOLOGAÇÃO');
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('o gráfico tem uma tabela equivalente para leitor de tela com os 12 meses', () => {
    render(<PainelFiscal />);

    const tabela = screen.getByRole('table', { name: 'Faturamento dos últimos 12 meses' });
    expect(within(tabela).getAllByRole('row')).toHaveLength(13);
    expect(within(tabela).getByText('set/2026')).toBeInTheDocument();
    expect(within(tabela).getByText(/R\$\s*500,00/)).toBeInTheDocument();
  });

  it('mês sem notas mostra a mensagem de vazio', () => {
    comDados(dados({ mes: { periodo: '2026-10', notas: [], valor_emitido_centavos: 0 } }));
    render(<PainelFiscal />);

    expect(screen.getByText('Nenhuma nota neste mês ainda.')).toBeInTheDocument();
  });

  it('mostra carregando e erro', () => {
    vi.mocked(useDashboardFiscal).mockReturnValue({ data: undefined, isPending: true, isError: false } as never);
    const { unmount } = render(<PainelFiscal />);
    expect(screen.getByText('Carregando o painel fiscal...')).toBeInTheDocument();
    unmount();

    vi.mocked(useDashboardFiscal).mockReturnValue({ data: undefined, isPending: false, isError: true } as never);
    render(<PainelFiscal />);
    expect(screen.getByRole('alert')).toHaveTextContent('Não foi possível carregar o painel fiscal.');
  });
});
