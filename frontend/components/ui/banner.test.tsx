import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Banner } from './banner';

describe('Banner', () => {
  it('erro é anunciado como alerta; os demais como status', () => {
    render(<><Banner tom="perigo">Falhou</Banner><Banner tom="info">Aviso</Banner></>);
    expect(screen.getByRole('alert')).toHaveTextContent('Falhou');
    expect(screen.getByRole('status')).toHaveTextContent('Aviso');
  });

  it('só tem botão de dispensar quando recebe onDispensar', async () => {
    const aoDispensar = vi.fn();
    const { rerender } = render(<Banner tom="alerta">x</Banner>);
    expect(screen.queryByRole('button', { name: 'Dispensar' })).not.toBeInTheDocument();
    rerender(<Banner tom="alerta" onDispensar={aoDispensar}>x</Banner>);
    await userEvent.click(screen.getByRole('button', { name: 'Dispensar' }));
    expect(aoDispensar).toHaveBeenCalledOnce();
  });
});
