import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { crmApi } from '../api';
import { useContato, useResumoDoCrm } from './useAtividades';

vi.mock('../api', async (original) => {
  const real = await original<typeof import('../api')>();
  return {
    ...real,
    crmApi: {
      ...real.crmApi,
      resumo: vi.fn(async () => ({ data: { hoje: 2, atrasadas: 1, parados: 3 } })),
      registrarContato: vi.fn(async () => ({
        data: { url: 'https://wa.me/5511999990000', atividade: { id: 'a1' } },
      })),
    },
  };
});

function envoltorio() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  function Envoltorio({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  }
  return Envoltorio;
}

describe('hooks de atividades', () => {
  it('carrega o resumo do CRM', async () => {
    const { result } = renderHook(() => useResumoDoCrm(), { wrapper: envoltorio() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toEqual({ hoje: 2, atrasadas: 1, parados: 3 });
  });

  it('abre o canal só depois do registro e devolve a url', async () => {
    const abrir = vi.fn();
    vi.stubGlobal('open', abrir);
    const { result } = renderHook(() => useContato(), { wrapper: envoltorio() });

    result.current.mutate({ canal: 'WHATSAPP', clienteId: 'c1' });

    await waitFor(() => expect(abrir).toHaveBeenCalledWith('https://wa.me/5511999990000', '_blank', 'noopener,noreferrer'));
    expect(crmApi.registrarContato).toHaveBeenCalledWith('WHATSAPP', { cliente_id: 'c1', negocio_id: undefined });
  });
});
