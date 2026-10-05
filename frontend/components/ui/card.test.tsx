import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Card, CardContent, CardHeader, CardTitle } from './card';

describe('Card', () => {
  it('tem fundo de cartão e borda estrutural', () => {
    render(<Card data-testid="c"><CardHeader><CardTitle>Título</CardTitle></CardHeader><CardContent>corpo</CardContent></Card>);
    const cartao = screen.getByTestId('c');
    expect(cartao.className).toContain('bg-card');
    expect(cartao.className).toContain('border');
    expect(screen.getByText('Título').className).toContain('font-display');
  });

  it('size="sm" é exposto por atributo', () => {
    render(<Card size="sm" data-testid="c" />);
    expect(screen.getByTestId('c')).toHaveAttribute('data-size', 'sm');
  });
});
