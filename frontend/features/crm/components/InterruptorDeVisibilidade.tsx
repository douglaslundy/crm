'use client';

import { useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Banner } from '@/components/ui/banner';
import { ApiError } from '@/lib/api';
import { crmApi } from '../api';
import { QUERY_KEY_CONFIGURACAO_CRM, useConfiguracaoCrm } from '../hooks/useConfiguracaoCrm';

export function InterruptorDeVisibilidade({ podeEditar }: { podeEditar: boolean }) {
  const queryClient = useQueryClient();
  const { data, isPending, isError } = useConfiguracaoCrm();
  const salvar = useMutation({
    mutationFn: (valor: boolean) => crmApi.salvarConfiguracao(valor),
    onSuccess: () => {
      toast.success('Configuração salva.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_CONFIGURACAO_CRM });
    },
    onError: (erro) => toast.error(erro instanceof ApiError ? erro.message : 'Tente novamente.'),
  });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <Banner tom="perigo">Não foi possível carregar a configuração.</Banner>;

  return (
    <label className="flex items-start gap-2 text-sm">
      <input
        type="checkbox"
        className="mt-1"
        aria-label="Vendedor vê só os próprios negócios"
        checked={data.vendedor_ve_so_os_seus}
        disabled={!podeEditar || salvar.isPending}
        onChange={(e) => salvar.mutate(e.target.checked)}
      />
      <span>
        Vendedor vê só os próprios negócios
        <span className="block text-xs text-muted-foreground">
          Desligado, todo vendedor enxerga os negócios da empresa. Proprietário, administrador e leitura sempre veem todos.
        </span>
      </span>
    </label>
  );
}
