import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { LayoutDashboard } from 'lucide-react';
import { describe, expect, it, vi } from 'vitest';
import { MenuMobile } from './MenuMobile';
import type { NavItem } from './nav-items';

vi.mock('next/navigation', () => ({ usePathname: () => '/dashboard' }));

const itens: NavItem[] = [{ href: '/dashboard', label: 'Início', icon: LayoutDashboard }];

function abrir() {
  render(<QueryClientProvider client={new QueryClient()}><MenuMobile itens={itens} /></QueryClientProvider>);
}

describe('MenuMobile', () => {
  it('começa fechado e abre pelo botão', async () => {
    abrir();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    const botao = screen.getByRole('button', { name: 'Abrir menu' });
    expect(botao).toHaveAttribute('aria-expanded', 'false');
    await userEvent.click(botao);
    expect(screen.getByRole('dialog', { name: 'Menu' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Início/ })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('button', { name: 'Abrir menu' })).toHaveAttribute('aria-expanded', 'true');
  });

  it('Esc fecha e devolve o foco ao botão', async () => {
    abrir();
    await userEvent.click(screen.getByRole('button', { name: 'Abrir menu' }));
    await userEvent.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Abrir menu' })).toHaveFocus();
  });

  it('o véu fecha o menu', async () => {
    abrir();
    await userEvent.click(screen.getByRole('button', { name: 'Abrir menu' }));
    await userEvent.click(screen.getByTestId('veu-do-menu'));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('navegar por um item fecha o menu', async () => {
    abrir();
    await userEvent.click(screen.getByRole('button', { name: 'Abrir menu' }));
    await userEvent.click(screen.getByRole('link', { name: /Início/ }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });
});
