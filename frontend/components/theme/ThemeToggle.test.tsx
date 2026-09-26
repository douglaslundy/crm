import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ThemeToggle } from './ThemeToggle';

const setTheme = vi.fn();
let resolvedTheme = 'light';

vi.mock('next-themes', () => ({
  useTheme: () => ({ resolvedTheme, setTheme }),
}));

describe('ThemeToggle', () => {
  beforeEach(() => setTheme.mockClear());

  it('no tema claro, oferece e ativa o tema escuro', async () => {
    resolvedTheme = 'light';
    render(<ThemeToggle />);

    await userEvent.click(screen.getByRole('button', { name: 'Ativar tema escuro' }));

    expect(setTheme).toHaveBeenCalledWith('dark');
  });

  it('no tema escuro, oferece e ativa o tema claro', async () => {
    resolvedTheme = 'dark';
    render(<ThemeToggle />);

    await userEvent.click(screen.getByRole('button', { name: 'Ativar tema claro' }));

    expect(setTheme).toHaveBeenCalledWith('light');
  });
});
