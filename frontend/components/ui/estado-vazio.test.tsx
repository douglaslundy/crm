import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { EstadoVazio } from './estado-vazio';

describe('EstadoVazio', () => {
  it('mostra título e dica', () => {
    render(<EstadoVazio titulo="Nenhum produto cadastrado">Cadastre o primeiro.</EstadoVazio>);
    expect(screen.getByRole('status')).toHaveTextContent('Nenhum produto cadastrado');
    expect(screen.getByText('Cadastre o primeiro.')).toBeInTheDocument();
  });

  it('funciona sem dica', () => {
    render(<EstadoVazio titulo="Nada aqui" />);
    expect(screen.getByRole('status')).toHaveTextContent('Nada aqui');
  });
});
