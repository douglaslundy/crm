import { useQuery } from '@tanstack/react-query';
import { crmApi, QUERY_KEY_CRM } from '../api';
import type { ConfiguracaoDoCrm } from '../types';

export const QUERY_KEY_CONFIGURACAO_CRM = [...QUERY_KEY_CRM, 'configuracao'] as const;

export function useConfiguracaoCrm() {
  return useQuery<ConfiguracaoDoCrm>({
    queryKey: QUERY_KEY_CONFIGURACAO_CRM,
    queryFn: async () => (await crmApi.configuracao()).data,
  });
}
