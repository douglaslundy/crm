import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { BarraDeProgresso } from './barra-de-progresso';

describe('BarraDeProgresso', () => {
  it('expõe o progresso para leitores de tela', () => {
    render(<BarraDeProgresso valor={40} maximo={100} rotulo="Notas do mês" />);
    const barra = screen.getByRole('progressbar', { name: 'Notas do mês' });
    expect(barra).toHaveAttribute('aria-valuenow', '40');
    expect(barra).toHaveAttribute('aria-valuemax', '100');
    expect(barra).toHaveAttribute('data-faixa', 'ok');
  });

  it.each([[79, 'ok'], [80, 'alerta'], [99, 'alerta'], [100, 'perigo'], [130, 'perigo']])('faixa de %i%%', (valor, faixa) => {
    render(<BarraDeProgresso valor={valor} maximo={100} rotulo="x" />);
    expect(screen.getByRole('progressbar')).toHaveAttribute('data-faixa', faixa);
  });

  it('máximo zero não divide por zero', () => {
    render(<BarraDeProgresso valor={0} maximo={0} rotulo="x" />);
    expect(screen.getByRole('progressbar')).toHaveAttribute('data-faixa', 'ok');
  });
});
