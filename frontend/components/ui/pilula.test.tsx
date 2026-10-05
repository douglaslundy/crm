import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Pilula } from './pilula';

describe('Pilula', () => {
  it('mostra o rótulo em texto e expõe o tom', () => {
    render(<Pilula tom="perigo">Rejeitada</Pilula>);
    expect(screen.getByText('Rejeitada')).toHaveAttribute('data-tom', 'perigo');
  });

  it('contorno é marcado por atributo', () => {
    render(<Pilula tom="perigo" contorno>Cancelada</Pilula>);
    expect(screen.getByText('Cancelada')).toHaveAttribute('data-contorno', 'true');
  });

  it('sólida de sucesso usa o fundo forte com texto branco', () => {
    render(<Pilula tom="sucesso">Autorizada</Pilula>);
    const el = screen.getByText('Autorizada');
    expect(el.className).toContain('bg-success-strong');
    expect(el.className).toContain('text-white');
  });

  it('sólida de alerta usa a marca com texto preto', () => {
    render(<Pilula tom="alerta">Processando</Pilula>);
    const el = screen.getByText('Processando');
    expect(el.className).toContain('bg-primary');
    expect(el.className).toContain('text-primary-foreground');
  });
});
