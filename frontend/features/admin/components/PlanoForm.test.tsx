import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import type { PlanoEntrada } from '../types';
import { PlanoForm } from './PlanoForm';

describe('PlanoForm', () => {
  it('"Ilimitado" grava -1, o preço vira centavos e os módulos marcados vão juntos', async () => {
    const onSalvar = vi.fn().mockResolvedValue(undefined);
    render(<PlanoForm rotuloBotao="Criar plano" onSalvar={onSalvar} />);

    await userEvent.type(screen.getByLabelText('Nome'), 'Essencial');
    await userEvent.type(screen.getByLabelText('Preço mensal (R$)'), '99,90');
    await userEvent.click(within(screen.getByRole('group', { name: 'Usuários' })).getByLabelText('Ilimitado'));
    const clientes = within(screen.getByRole('group', { name: 'Clientes' })).getByLabelText('Quantidade');
    await userEvent.clear(clientes);
    await userEvent.type(clientes, '500');
    await userEvent.click(screen.getByLabelText('NF-e'));
    await userEvent.click(screen.getByRole('button', { name: 'Criar plano' }));

    await waitFor(() => expect(onSalvar).toHaveBeenCalledTimes(1));
    const entrada = onSalvar.mock.calls[0][0] as PlanoEntrada;
    expect(entrada.preco_mensal_centavos).toBe(9990);
    expect(entrada.limites.USUARIOS).toBe(-1);
    expect(entrada.limites.CLIENTES).toBe(500);
    expect(entrada.limites.PRODUTOS).toBe(0);
    expect(entrada.modulos).toEqual(['FISCAL_NFE']);
  });

  it('cobrar excedente exige o preço por documento', async () => {
    const onSalvar = vi.fn();
    render(<PlanoForm rotuloBotao="Criar plano" onSalvar={onSalvar} />);

    await userEvent.type(screen.getByLabelText('Nome'), 'X');
    await userEvent.type(screen.getByLabelText('Preço mensal (R$)'), '10');
    await userEvent.selectOptions(screen.getByLabelText('Política de excedente'), 'COBRAR');
    await userEvent.click(screen.getByRole('button', { name: 'Criar plano' }));

    expect(await screen.findByLabelText('Preço por documento excedente (R$)')).toHaveAccessibleDescription(
      'Informe o preço por documento excedente.',
    );
    expect(onSalvar).not.toHaveBeenCalled();
  });

  it('erro de validação da API aparece no campo', async () => {
    const onSalvar = vi.fn().mockRejectedValue(new ApiError(422, 'x', { nome: ['O nome já está em uso.'] }));
    render(<PlanoForm rotuloBotao="Criar plano" onSalvar={onSalvar} />);

    await userEvent.type(screen.getByLabelText('Nome'), 'Essencial');
    await userEvent.type(screen.getByLabelText('Preço mensal (R$)'), '10');
    await userEvent.click(screen.getByRole('button', { name: 'Criar plano' }));

    expect(await screen.findByLabelText('Nome')).toHaveAccessibleDescription('O nome já está em uso.');
  });
});
