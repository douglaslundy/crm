import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ApiError } from '@/lib/api';
import { crmApi, QUERY_KEY_CRM } from '../api';
import type { MetaDoVendedor, UnidadeDaMeta } from '../types';

const mensagem = (erro: unknown, padrao: string) => (erro instanceof ApiError ? erro.message : padrao);

export function useMetas(mes: string | null) {
  return useQuery<MetaDoVendedor[]>({
    queryKey: [...QUERY_KEY_CRM, 'metas', mes ?? ''],
    queryFn: async () => (await crmApi.metas(mes as string)).data,
    enabled: mes !== null,
  });
}

/** Meta muda o progresso nos relatórios e no painel: invalida o CRM inteiro. */
function useInvalidarCrm() {
  const client = useQueryClient();
  return () => client.invalidateQueries({ queryKey: QUERY_KEY_CRM });
}

export function useDefinirMeta() {
  const invalidar = useInvalidarCrm();
  return useMutation({
    mutationFn: (dados: { usuario_id: string; mes: string; unidade: UnidadeDaMeta; alvo: number }) => crmApi.definirMeta(dados),
    onSuccess: () => {
      toast.success('Meta salva.');
      return invalidar();
    },
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível salvar a meta.')),
  });
}

export function useRemoverMeta() {
  const invalidar = useInvalidarCrm();
  return useMutation({
    mutationFn: (id: string) => crmApi.removerMeta(id),
    onSuccess: () => {
      toast.success('Meta removida.');
      return invalidar();
    },
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível remover a meta.')),
  });
}
