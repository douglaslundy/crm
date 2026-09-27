import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { QUERY_KEY_USUARIO } from '@/features/auth/hooks/useUsuario';
import type { SituacaoAssinatura } from '@/features/auth/types';
import { AreaDaEmpresa } from './AreaDaEmpresa';
import { ConsumoDoPlano } from './ConsumoDoPlano';
import { FaixaSituacao } from './FaixaSituacao';

function comUsuarioEm(situacao: SituacaoAssinatura) {
  const client = new QueryClient();
  client.setQueryData(QUERY_KEY_USUARIO, {
    id: '1', nome: 'Ana', email: 'ana@x.com', papel: 'PROPRIETARIO',
    tenant: { id: 't', razao_social: 'X Ltda', nome_fantasia: null, cnpj: '11222333000181', situacao, teste_termina_em: '2026-10-15' },
  });
  render(<QueryClientProvider client={client}><AreaDaEmpresa><p>conteúdo</p></AreaDaEmpresa></QueryClientProvider>);
}

describe('FaixaSituacao', () => {
  it('teste mostra a data de término', () => {
    render(<FaixaSituacao situacao="TESTE" testeTerminaEm="2026-10-15" />);
    expect(screen.getByRole('status')).toHaveTextContent('Teste grátis até 15/10/2026');
  });

  it('suspensa avisa que o download continua liberado', () => {
    render(<FaixaSituacao situacao="SUSPENSA" testeTerminaEm={null} />);
    expect(screen.getByRole('status')).toHaveTextContent('Conta suspensa. Você ainda pode consultar e baixar seus dados.');
  });

  it('ativa não mostra faixa', () => {
    const { container } = render(<FaixaSituacao situacao="ATIVA" testeTerminaEm={null} />);
    expect(container).toBeEmptyDOMElement();
  });
});

describe('AreaDaEmpresa', () => {
  it('conta pendente vê "Aguardando ativação" no lugar do conteúdo', () => {
    comUsuarioEm('PENDENTE');
    expect(screen.getByRole('heading', { name: 'Aguardando ativação' })).toBeInTheDocument();
    expect(screen.queryByText('conteúdo')).not.toBeInTheDocument();
  });

  it('conta em teste vê a faixa e o conteúdo', () => {
    comUsuarioEm('TESTE');
    expect(screen.getByRole('status')).toHaveTextContent('Teste grátis');
    expect(screen.getByText('conteúdo')).toBeInTheDocument();
  });
});

describe('ConsumoDoPlano', () => {
  it('mostra uso, limite e ilimitado', () => {
    render(<ConsumoDoPlano itens={[
      { recurso: 'USUARIOS', uso: 1, limite: 3 },
      { recurso: 'CLIENTES', uso: 40, limite: -1 },
    ]} />);

    expect(screen.getByText('1 de 3')).toBeInTheDocument();
    expect(screen.getByRole('progressbar', { name: 'Usuários' })).toHaveAttribute('aria-valuenow', '1');
    expect(screen.getByText('40 · ilimitado')).toBeInTheDocument();
  });
});
