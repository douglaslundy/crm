import { useQuery } from '@tanstack/react-query';
import { crmApi, QUERY_KEY_CRM } from '../api';
import type { Periodo } from '../periodos';
import type { RelatorioComercial } from '../types';

export function useRelatorio(periodo: Periodo, vendedorId?: string, funilId?: string) {
  return useQuery<RelatorioComercial>({
    queryKey: [...QUERY_KEY_CRM, 'relatorio', periodo.de, periodo.ate, vendedorId ?? '', funilId ?? ''],
    queryFn: async () => (await crmApi.relatorio({ ...periodo, vendedor_id: vendedorId, funil_id: funilId })).data,
  });
}
