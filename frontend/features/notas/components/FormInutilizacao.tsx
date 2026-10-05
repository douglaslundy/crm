'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { TextareaNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { inutilizacoesApi, QUERY_KEY_INUTILIZACOES } from '../api';
import { inutilizacaoSchema, paraPayloadInutilizacao, type InutilizacaoDados } from '../schemas';
import { ROTULO_STATUS_INUTILIZACAO } from '../types';
import { Banner } from '@/components/ui/banner';

const VAZIO: InutilizacaoDados = { serie: '', numero_inicial: '', numero_final: '', justificativa: '' };

export function FormInutilizacao() {
  const queryClient = useQueryClient();
  const form = useForm<InutilizacaoDados>({ resolver: zodResolver(inutilizacaoSchema), defaultValues: VAZIO });
  const enviar = useMutation({
    mutationFn: (d: InutilizacaoDados) => inutilizacoesApi.criar(paraPayloadInutilizacao(d)),
    onSuccess: ({ data }) => {
      toast.success(`Inutilização: ${ROTULO_STATUS_INUTILIZACAO[data.status]}.`);
      form.reset(VAZIO);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_INUTILIZACOES });
    },
    onError: (erro) => {
      form.setError('root', { message: erro instanceof ApiError ? erro.primeiraMensagem() : 'Não foi possível inutilizar. Tente novamente.' });
    },
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await enviar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id="inut_serie" label="Série" erro={erros.serie?.message}>
        {(a11y) => <Input {...a11y} inputMode="numeric" {...form.register('serie')} />}
      </Campo>
      <Campo id="inut_inicial" label="Número inicial" erro={erros.numero_inicial?.message}>
        {(a11y) => <Input {...a11y} inputMode="numeric" {...form.register('numero_inicial')} />}
      </Campo>
      <Campo id="inut_final" label="Número final" erro={erros.numero_final?.message}>
        {(a11y) => <Input {...a11y} inputMode="numeric" {...form.register('numero_final')} />}
      </Campo>
      <Campo id="inut_justificativa" label="Justificativa" dica="De 15 a 255 caracteres." erro={erros.justificativa?.message}>
        {(a11y) => <TextareaNativo {...a11y} rows={3} {...form.register('justificativa')} />}
      </Campo>
      {erros.root ? <Banner tom="perigo">{erros.root.message}</Banner> : null}
      <Button type="submit" disabled={form.formState.isSubmitting}>Inutilizar numeração</Button>
    </form>
  );
}
