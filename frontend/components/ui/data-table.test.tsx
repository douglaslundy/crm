import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { DataTable, type Coluna } from './data-table';

interface Linha { id: string; nome: string; valor: string }
const colunas: Coluna<Linha>[] = [
  { chave: 'nome', titulo: 'Nome', render: (l) => l.nome },
  { chave: 'valor', titulo: 'Valor', render: (l) => l.valor, numerica: true },
];
const linhas: Linha[] = [{ id: '1', nome: 'Ana', valor: '10,00' }, { id: '2', nome: 'Bia', valor: '20,00' }];

describe('DataTable', () => {
  it('renderiza cabeçalhos e uma linha por registro', () => {
    render(<DataTable rotulo="Clientes" colunas={colunas} linhas={linhas} chaveDaLinha={(l) => l.id} />);
    const tabela = screen.getByRole('table', { name: 'Clientes' });
    expect(within(tabela).getAllByRole('columnheader')).toHaveLength(2);
    expect(within(tabela).getAllByRole('row')).toHaveLength(3);
    expect(screen.getByText('10,00').className).toContain('font-mono');
  });

  it('sem linhas mostra a mensagem de vazio', () => {
    render(<DataTable rotulo="Clientes" colunas={colunas} linhas={[]} chaveDaLinha={(l) => l.id} vazio="Nenhum cliente." />);
    expect(screen.getByText('Nenhum cliente.')).toBeInTheDocument();
  });

  it('carregando mostra esqueleto e marca aria-busy', () => {
    render(<DataTable rotulo="Clientes" colunas={colunas} linhas={[]} chaveDaLinha={(l) => l.id} carregando />);
    expect(screen.getByRole('table')).toHaveAttribute('aria-busy', 'true');
    expect(screen.getAllByTestId('linha-esqueleto')).toHaveLength(5);
  });

  it('linha abre por clique e por teclado', async () => {
    const aoAbrir = vi.fn();
    render(<DataTable rotulo="Clientes" colunas={colunas} linhas={linhas} chaveDaLinha={(l) => l.id} aoAbrir={aoAbrir} />);
    const ana = screen.getByText('Ana').closest('tr')!;
    await userEvent.click(ana);
    expect(aoAbrir).toHaveBeenCalledWith(linhas[0]);
    const bia = screen.getByText('Bia').closest('tr')!;
    bia.focus();
    await userEvent.keyboard('{Enter}');
    expect(aoAbrir).toHaveBeenLastCalledWith(linhas[1]);
  });
});
