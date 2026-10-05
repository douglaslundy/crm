import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Campo } from './Campo';

describe('Campo', () => {
  it('liga o erro ao campo por aria-describedby', () => {
    render(<Campo id="email" label="E-mail" erro="Informe um e-mail válido.">{(a11y) => <input {...a11y} />}</Campo>);

    const campo = screen.getByLabelText('E-mail');
    expect(campo).toHaveAttribute('aria-invalid', 'true');
    expect(campo).toHaveAccessibleDescription('Informe um e-mail válido.');
  });

  it('sem erro, só a dica descreve o campo', () => {
    render(<Campo id="senha" label="Senha" dica="Mínimo de 8 caracteres.">{(a11y) => <input {...a11y} />}</Campo>);

    const campo = screen.getByLabelText('Senha');
    expect(campo).toHaveAttribute('aria-invalid', 'false');
    expect(campo).toHaveAccessibleDescription('Mínimo de 8 caracteres.');
  });
});

describe('Campo obrigatório', () => {
  it('mostra asterisco e anuncia "obrigatório" ao leitor de tela', () => {
    render(<Campo id="nome" label="Nome" obrigatorio>{(a11y) => <input {...a11y} />}</Campo>);

    expect(screen.getByText('(obrigatório)')).toHaveClass('sr-only');
    expect(screen.getByText('*')).toHaveAttribute('aria-hidden', 'true');
  });
});
