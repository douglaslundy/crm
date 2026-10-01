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
import { dadosDaEmpresaSchema, type DadosDaEmpresaFormDados } from '../schemas';
import type { Emitente } from '../types';

export function DadosDaEmpresaForm({ emitente, onSalvar }: { emitente: Emitente; onSalvar?: () => void }) {
  const queryClient = useQueryClient();
  const form = useForm<DadosDaEmpresaFormDados>({
    resolver: zodResolver(dadosDaEmpresaSchema),
    defaultValues: {
      cep: emitente.cep ?? '', logradouro: emitente.logradouro ?? '', numero: emitente.numero ?? '',
      bairro: emitente.bairro ?? '', cidade: emitente.cidade ?? '', uf: emitente.uf ?? '', codigo_ibge: emitente.codigo_ibge ?? '',
    },
  });
  const buscarCep = useMutation({
    mutationFn: (cep: string) => emitenteApi.consultarCep(cep),
    onSuccess: ({ data }) => {
      form.setValue('logradouro', data.logradouro);
      form.setValue('bairro', data.bairro);
      form.setValue('cidade', data.cidade);
      form.setValue('uf', data.uf);
      form.setValue('codigo_ibge', data.codigo_ibge);
    },
    onError: () => toast.error('CEP não encontrado. Preencha o endereço manualmente.'),
  });
  const salvar = useMutation({
    mutationFn: (d: DadosDaEmpresaFormDados) => emitenteApi.atualizarEmpresa(d),
    onSuccess: () => {
      toast.success('Endereço salvo.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_EMITENTE });
      onSalvar?.();
    },
    onError: (erro) => form.setError('root', { message: erro instanceof ApiError ? erro.message : 'Não foi possível salvar.' }),
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      <div className="flex items-end gap-2">
        <div className="flex-1">
          <Campo id="empresa_cep" label="CEP" erro={erros.cep?.message}>
            {(a11y) => <Input {...a11y} {...form.register('cep')} />}
          </Campo>
        </div>
        <Button type="button" variant="outline" size="sm" disabled={buscarCep.isPending} onClick={() => buscarCep.mutate(form.getValues('cep') ?? '')}>
          Buscar
        </Button>
      </div>
      <Campo id="empresa_logradouro" label="Logradouro" erro={erros.logradouro?.message}>
        {(a11y) => <Input {...a11y} {...form.register('logradouro')} />}
      </Campo>
      <Campo id="empresa_numero" label="Número" erro={erros.numero?.message}>
        {(a11y) => <Input {...a11y} {...form.register('numero')} />}
      </Campo>
      <Campo id="empresa_bairro" label="Bairro" erro={erros.bairro?.message}>
        {(a11y) => <Input {...a11y} {...form.register('bairro')} />}
      </Campo>
      <Campo id="empresa_cidade" label="Cidade" erro={erros.cidade?.message}>
        {(a11y) => <Input {...a11y} {...form.register('cidade')} />}
      </Campo>
      <Campo id="empresa_uf" label="UF" erro={erros.uf?.message}>
        {(a11y) => <Input {...a11y} maxLength={2} {...form.register('uf')} />}
      </Campo>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>Salvar endereço</Button>
    </form>
  );
}
