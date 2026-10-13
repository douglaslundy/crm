import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { SeloDeParado } from './SeloDeParado';

describe('SeloDeParado', () => {
  it('mostra os dias parado', () => {
    render(<SeloDeParado dias={5} />);
    expect(screen.getByText('Parado há 5 dias')).toBeInTheDocument();
  });

  it('usa singular com 1 dia', () => {
    render(<SeloDeParado dias={1} />);
    expect(screen.getByText('Parado há 1 dia')).toBeInTheDocument();
  });
});
