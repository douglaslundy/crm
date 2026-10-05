import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { CabecalhoDePagina } from './CabecalhoDePagina';

describe('CabecalhoDePagina', () => {
  it('título é o h1; subtítulo e ações aparecem', () => {
    render(<CabecalhoDePagina titulo="Clientes" subtitulo="12 registros" acoes={<button>Novo</button>} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Clientes' })).toBeInTheDocument();
    expect(screen.getByText('12 registros')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Novo' })).toBeInTheDocument();
  });
});
