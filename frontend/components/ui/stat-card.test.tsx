import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { StatCard } from './stat-card';

describe('StatCard', () => {
  it('mostra título, valor e legenda', () => {
    render(<StatCard titulo="Clientes" valor="42" legenda="ativos" />);
    expect(screen.getByText('Clientes')).toBeInTheDocument();
    expect(screen.getByText('42')).toBeInTheDocument();
    expect(screen.getByText('ativos')).toBeInTheDocument();
  });

  it('com href vira link', () => {
    render(<StatCard titulo="Pendências" valor="3" href="/pendencias-fiscais" />);
    expect(screen.getByRole('link')).toHaveAttribute('href', '/pendencias-fiscais');
  });
});
