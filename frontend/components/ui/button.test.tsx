import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Button } from './button';

describe('Button', () => {
  it('primário usa a cor da marca e a fonte de títulos', () => {
    render(<Button>Salvar</Button>);
    const botao = screen.getByRole('button', { name: 'Salvar' });
    expect(botao.className).toContain('bg-primary');
    expect(botao.className).toContain('font-display');
  });

  it('outline usa a borda estrutural', () => {
    render(<Button variant="outline">Cancelar</Button>);
    expect(screen.getByRole('button').className).toContain('border-border');
  });

  it('destructive e success usam texto semântico', () => {
    render(<><Button variant="destructive">Excluir</Button><Button variant="success">Ok</Button></>);
    expect(screen.getByRole('button', { name: 'Excluir' }).className).toContain('text-danger');
    expect(screen.getByRole('button', { name: 'Ok' }).className).toContain('text-success');
  });

  it('desabilitado não dispara o clique', async () => {
    const aoClicar = vi.fn();
    render(<Button disabled onClick={aoClicar}>Enviar</Button>);
    await userEvent.click(screen.getByRole('button'));
    expect(aoClicar).not.toHaveBeenCalled();
  });

  it('tamanho icon é quadrado de 36px', () => {
    render(<Button size="icon" aria-label="Fechar" />);
    expect(screen.getByRole('button').className).toContain('size-9');
  });
});
