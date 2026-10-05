import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { AuthCard } from './AuthCard';

describe('AuthCard', () => {
  it('mostra título, descrição, conteúdo e a marca', () => {
    render(<AuthCard titulo="Entrar" descricao="Acesse sua conta."><p>formulário</p></AuthCard>);
    expect(screen.getByText('Entrar')).toBeInTheDocument();
    expect(screen.getByText('Acesse sua conta.')).toBeInTheDocument();
    expect(screen.getByText('formulário')).toBeInTheDocument();
    expect(screen.getByTestId('painel-de-marca')).toHaveTextContent('Plataforma Fiscal');
  });
});
