import { render, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { Providers } from './providers';

vi.mock('@/lib/sessao', () => ({ irParaLoginSeNaoAutenticado: vi.fn() }));
vi.mock('@/components/ui/sonner', () => ({ Toaster: () => null }));

describe('Providers', () => {
  it('abre no tema escuro quando não há preferência salva', async () => {
    window.matchMedia = vi.fn().mockReturnValue({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn(), addListener: vi.fn(), removeListener: vi.fn() });
    localStorage.clear();
    document.documentElement.className = '';
    render(<Providers><span>x</span></Providers>);
    await waitFor(() => expect(document.documentElement.classList.contains('dark')).toBe(true));
  });
});
