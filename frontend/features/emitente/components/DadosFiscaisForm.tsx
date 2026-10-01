'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Button } from '@/components/ui/button';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { emitenteApi, QUERY_KEY_EMITENTE } from '../api';
import { dadosFiscaisSchema, type DadosFiscaisFormDados } from '../schemas';
import type { Emitente } from '../types';

export function DadosFiscaisForm({ emitente, onSalvar }: { emitente: Emitente; onSalvar?: () => void }) {
  const queryClient = useQueryClient();
  const form = useForm<DadosFiscaisFormDados>({
    resolver: zodResolver(dadosFiscaisSchema),
    defaultValues: {
      regime_tributario: (emitente.regime_tributario as DadosFiscaisFormDados['regime_tributario']) ?? '',
      inscricao_estadual: emitente.inscricao_estadual ?? '', inscricao_municipal: emitente.inscricao_municipal ?? '', cnae: emitente.cnae ?? '',
    },
  });
  const salvar = useMutation({
    mutationFn: (d: DadosFiscaisFormDados) => emitenteApi.atualizarFiscal(d),
    onSuccess: () => {
      toast.success('Dados fiscais salvos.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_EMITENTE });
      onSalvar?.();
    },
    onError: (erro) => form.setError('root', { message: erro instanceof ApiError ? erro.message : 'Não foi possível salvar.' }),
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id="fiscal_regime" label="Regime tributário" erro={erros.regime_tributario?.message}>
        {(a11y) => (
          <SelectNativo {...a11y} {...form.register('regime_tributario')}>
            <option value="">Não informado</option>
            <option value="SIMPLES">Simples Nacional</option>
            <option value="MEI">MEI</option>
            <option value="NORMAL">Regime Normal</option>
          </SelectNativo>
        )}
      </Campo>
      <Campo id="fiscal_ie" label="Inscrição estadual" erro={erros.inscricao_estadual?.message}>
        {(a11y) => <Input {...a11y} {...form.register('inscricao_estadual')} />}
      </Campo>
      <Campo id="fiscal_im" label="Inscrição municipal" erro={erros.inscricao_municipal?.message}>
        {(a11y) => <Input {...a11y} {...form.register('inscricao_municipal')} />}
      </Campo>
      <Campo id="fiscal_cnae" label="CNAE" erro={erros.cnae?.message}>
        {(a11y) => <Input {...a11y} {...form.register('cnae')} />}
      </Campo>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>Salvar dados fiscais</Button>
    </form>
  );
}
