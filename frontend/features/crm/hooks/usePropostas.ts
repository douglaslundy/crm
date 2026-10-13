import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ApiError } from '@/lib/api';
import { crmApi, QUERY_KEY_CRM } from '../api';
import type { NovaPropostaPayload, Proposta } from '../types';

const mensagem = (erro: unknown, padrao: string) => (erro instanceof ApiError ? erro.message : padrao);

export function usePropostasDoNegocio(negocioId: string) {
  return useQuery<Proposta[]>({
    queryKey: [...QUERY_KEY_CRM, 'propostas', negocioId],
    queryFn: async () => (await crmApi.propostas(negocioId)).data,
  });
}

function useInvalidar() {
  const queryClient = useQueryClient();
  // Proposta mexe no negócio (aceite ganha), na timeline e nas notas geradas.
  return () => queryClient.invalidateQueries({ queryKey: QUERY_KEY_CRM });
}

export function useCriarProposta(negocioId: string) {
  const invalidar = useInvalidar();
  return useMutation({
    mutationFn: (dados: NovaPropostaPayload) => crmApi.criarProposta(negocioId, dados),
    onSuccess: () => {
      toast.success('Proposta criada.');
      void invalidar();
    },
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível criar a proposta.')),
  });
}

export function useEnviarProposta() {
  const invalidar = useInvalidar();
  return useMutation({
    mutationFn: (id: string) => crmApi.enviarProposta(id),
    onSuccess: () => {
      toast.success('Proposta enviada. Copie o link para o cliente.');
      void invalidar();
    },
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível enviar a proposta.')),
  });
}

export function useCancelarProposta() {
  const invalidar = useInvalidar();
  return useMutation({
    mutationFn: (id: string) => crmApi.cancelarProposta(id),
    onSuccess: () => void invalidar(),
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível cancelar a proposta.')),
  });
}

export function useGerarNotaDaProposta() {
  const invalidar = useInvalidar();
  return useMutation({
    mutationFn: (id: string) => crmApi.gerarNotaDaProposta(id),
    onSuccess: () => {
      toast.success('Rascunho de nota criado. Complete o pagamento e emita.');
      void invalidar();
    },
    onError: (erro) => toast.error(mensagem(erro, 'Não foi possível gerar o rascunho da nota.')),
  });
}
