import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { crmApi } from '@/features/crm/api';
import { EmblemaDeAtrasadas } from './EmblemaDeAtrasadas';

vi.mock('@/features/crm/api', async (original) => {
  const real = await original<typeof import('@/features/crm/api')>();
  return { ...real, crmApi: { ...real.crmApi, resumo: vi.fn() } };
});

function renderizar() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><EmblemaDeAtrasadas /></QueryClientProvider>);
}

describe('EmblemaDeAtrasadas', () => {
  it('mostra a quantidade de atividades atrasadas', async () => {
    vi.mocked(crmApi.resumo).mockResolvedValue({ data: { hoje: 0, atrasadas: 3, parados: 0, funil: null, previsao_do_mes: { quantidade: 0, valor_centavos: 0, ponderada_centavos: 0 }, meta_do_mes: null } });
    renderizar();

    expect(await screen.findByLabelText('3 atividades atrasadas')).toHaveTextContent('3');
  });

  it('não mostra nada sem atrasadas', async () => {
    vi.mocked(crmApi.resumo).mockResolvedValue({ data: { hoje: 1, atrasadas: 0, parados: 0, funil: null, previsao_do_mes: { quantidade: 0, valor_centavos: 0, ponderada_centavos: 0 }, meta_do_mes: null } });
    const { container } = renderizar();

    await vi.waitFor(() => expect(crmApi.resumo).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
  });
});
