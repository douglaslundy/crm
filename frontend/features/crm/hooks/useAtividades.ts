import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ApiError } from '@/lib/api';
import { crmApi, QUERY_KEY_CRM } from '../api';
import type { Atividade, NovaAtividadePayload, ResumoDoCrm } from '../types';

export const QUERY_KEY_ATIVIDADES = [...QUERY_KEY_CRM, 'atividades'] as const;

const mensagem = (erro: unknown, padrao: string) => (erro instanceof ApiError ? erro.message : padrao);

export function useAgenda(de: string, ate: string, responsavelId?: string) {
  return useQuery<Atividade[]>({
    queryKey: [...QUERY_KEY_ATIVIDADES, 'agenda', de, ate, responsavelId ?? ''],
    queryFn: async () => (await crmApi.agenda(de, ate, responsavelId)).data,
  });
}

export function useAtividadesDoNegocio(negocioId: string) {
  return useQuery<Atividade[]>({
    queryKey: [...QUERY_KEY_ATIVIDADES, 'negocio', negocioId],
    queryFn: async () => (await crmApi.atividades({ negocio_id: negocioId })).data,
  });
}

export function useAtividadesDoCliente(clienteId: string) {
  return useQuery<Atividade[]>({
    queryKey: [...QUERY_KEY_ATIVIDADES, 'cliente', clienteId],
    queryFn: async () => (await crmApi.atividades({ cliente_id: clienteId })).data,
  });
}

export function useResumoDoCrm() {
  return useQuery<ResumoDoCrm>({
    queryKey: [...QUERY_KEY_ATIVIDADES, 'resumo'],
    queryFn: async () => (await crmApi.resumo()).data,
    refetchInterval: 60_000,
  });
}

function useInvalidarAtividades() {
  const queryClient = useQueryClient();
  // Atividade mexe na agenda, no resumo, na timeline e no "parado" dos negócios.
  return () => queryClient.invalidateQueries({ queryKey: QUERY_KEY_CRM });
}

export function useCriarAtividade() {
  const invalidar = useInvalidarAtividades();
  return useMutation({
    mutationFn: (dados: NovaAtividadePayload) => crmApi.criarAtividade(dados),
    onSuccess: () => {
      toast.success('Atividade criada.');
      void invalidar();
    },
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível criar a atividade.')),
  });
}

export function useConcluirAtividade() {
  const invalidar = useInvalidarAtividades();
  return useMutation({
    mutationFn: (id: string) => crmApi.concluirAtividade(id),
    onSuccess: () => {
      toast.success('Atividade concluída.');
      void invalidar();
    },
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível concluir.')),
  });
}

export function useReabrirAtividade() {
  const invalidar = useInvalidarAtividades();
  return useMutation({
    mutationFn: (id: string) => crmApi.reabrirAtividade(id),
    onSuccess: () => void invalidar(),
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível reabrir.')),
  });
}

export function useExcluirAtividade() {
  const invalidar = useInvalidarAtividades();
  return useMutation({
    mutationFn: (id: string) => crmApi.excluirAtividade(id),
    onSuccess: () => void invalidar(),
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível excluir.')),
  });
}

/** Registra o contato e só então abre o canal; o servidor bloqueia dado inválido com mensagem acionável. */
export function useContato() {
  const invalidar = useInvalidarAtividades();
  return useMutation({
    mutationFn: ({ canal, clienteId, negocioId }: { canal: 'WHATSAPP' | 'EMAIL'; clienteId?: string; negocioId?: string }) =>
      crmApi.registrarContato(canal, { cliente_id: clienteId, negocio_id: negocioId }),
    onSuccess: ({ data }) => {
      window.open(data.url, '_blank', 'noopener,noreferrer');
      void invalidar();
    },
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível registrar o contato.')),
  });
}
