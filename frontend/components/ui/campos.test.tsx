import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Input } from './input';
import { SelectNativo, TextareaNativo } from './campos-nativos';

describe('campos', () => {
  it('Input aceita digitação e mantém aria-invalid', async () => {
    render(<Input aria-label="Nome" aria-invalid />);
    const campo = screen.getByRole('textbox', { name: 'Nome' });
    await userEvent.type(campo, 'Ana');
    expect(campo).toHaveValue('Ana');
    expect(campo).toHaveAttribute('aria-invalid', 'true');
  });

  it('SelectNativo lista opções, dispara onChange e mostra a seta', async () => {
    const aoMudar = vi.fn();
    render(
      <SelectNativo aria-label="UF" onChange={aoMudar}>
        <option value="SP">SP</option>
        <option value="RJ">RJ</option>
      </SelectNativo>,
    );
    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'UF' }), 'RJ');
    expect(aoMudar).toHaveBeenCalled();
    expect(screen.getByTestId('seta-select')).toBeInTheDocument();
  });

  it('TextareaNativo é um campo de texto multilinha', () => {
    render(<TextareaNativo aria-label="Obs" />);
    expect(screen.getByRole('textbox', { name: 'Obs' }).tagName).toBe('TEXTAREA');
  });
});
