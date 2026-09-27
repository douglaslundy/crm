import { focusManager, QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { authApi } from '../api';
import { useUsuario } from './useUsuario';

vi.mock('../api', () => ({ authApi: { me: vi.fn() } }));

describe('useUsuario', () => {
  afterEach(() => focusManager.setFocused(undefined));

  it('revalida o /me quando a janela volta a ter foco', async () => {
    vi.mocked(authApi.me).mockResolvedValue({
      data: { id: '1', nome: 'Ana', email: 'ana@x.com', papel: 'PROPRIETARIO', tenant: null },
    });
    const client = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );

    const { result } = renderHook(() => useUsuario(), { wrapper });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(authApi.me).toHaveBeenCalledTimes(1);

    act(() => {
      focusManager.setFocused(false);
      focusManager.setFocused(true);
    });

    await waitFor(() => expect(authApi.me).toHaveBeenCalledTimes(2));
  });
});
