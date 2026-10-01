'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { emitenteApi, QUERY_KEY_EMITENTE } from '../api';
import { serieSchema, type SerieFormDados } from '../schemas';
import type { Emitente, ModeloDocumento } from '../types';

export function SeriesForm({ emitente, modelo, rotulo, onSalvar }: { emitente: Emitente; modelo: ModeloDocumento; rotulo: string; onSalvar?: () => void }) {
  const queryClient = useQueryClient();
  const atual = emitente.series.find((s) => s.modelo === modelo);
  const form = useForm({
    resolver: zodResolver(serieSchema),
    defaultValues: { serie: atual?.serie ?? '', proximo_numero: atual?.proximo_numero ?? 1 },
  });
  const salvar = useMutation({
    mutationFn: (d: SerieFormDados) => emitenteApi.atualizarSerie(modelo, d.serie, d.proximo_numero),
    onSuccess: () => {
      toast.success(`Série de ${rotulo} salva.`);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_EMITENTE });
      onSalvar?.();
    },
    onError: (erro) => form.setError('root', { message: erro instanceof ApiError ? erro.message : 'Não foi possível salvar.' }),
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id={`serie_${modelo}`} label={`Série (${rotulo})`} erro={erros.serie?.message}>
        {(a11y) => <Input {...a11y} {...form.register('serie')} />}
      </Campo>
      <Campo id={`proximo_${modelo}`} label={`Próximo número (${rotulo})`} erro={erros.proximo_numero?.message}>
        {(a11y) => <Input {...a11y} type="number" min={1} {...form.register('proximo_numero')} />}
      </Campo>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>{`Salvar série (${rotulo})`}</Button>
    </form>
  );
}
