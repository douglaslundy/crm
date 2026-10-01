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
import { paraPayload, QUERY_KEY_SERVICOS, servicosApi } from '../api';
import { servicoSchema, type ServicoFormDados } from '../schemas';
import type { Servico } from '../types';

const VAZIO: ServicoFormDados = { nome: '', preco_centavos: 0, codigo_lc116: '', c_trib_nac: '', codigo_municipal: '', aliquota_iss: '', nbs: '' };

function paraFormulario(s: Servico): ServicoFormDados {
  return {
    nome: s.nome, preco_centavos: s.preco_centavos,
    codigo_lc116: s.codigo_lc116 ?? '', c_trib_nac: s.c_trib_nac ?? '',
    codigo_municipal: s.codigo_municipal ?? '', aliquota_iss: s.aliquota_iss ?? '', nbs: s.nbs ?? '',
  };
}

export function ServicoForm({ servico, onSalvar }: { servico?: Servico; onSalvar?: () => void }) {
  const queryClient = useQueryClient();
  const form = useForm<ServicoFormDados>({ resolver: zodResolver(servicoSchema), defaultValues: servico ? paraFormulario(servico) : VAZIO });
  const salvar = useMutation({
    mutationFn: (d: ServicoFormDados) => (servico ? servicosApi.editar(servico.id, paraPayload(d)) : servicosApi.criar(paraPayload(d))),
    onSuccess: () => {
      toast.success(servico ? 'Serviço atualizado.' : 'Serviço criado.');
      if (!servico) form.reset(VAZIO);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_SERVICOS });
      onSalvar?.();
    },
    onError: (erro) => {
      form.setError('root', { message: erro instanceof ApiError ? erro.primeiraMensagem() : 'Não foi possível salvar.' });
    },
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id="servico_nome" label="Nome" erro={erros.nome?.message}>
        {(a11y) => <Input {...a11y} {...form.register('nome')} />}
      </Campo>
      <Campo id="servico_preco" label="Preço (centavos)" erro={erros.preco_centavos?.message}>
        {(a11y) => <Input {...a11y} type="number" min={0} {...form.register('preco_centavos', { valueAsNumber: true })} />}
      </Campo>
      <Campo id="servico_lc116" label="Código LC 116" dica="Formato 00.00, ex.: 14.01." erro={erros.codigo_lc116?.message}>
        {(a11y) => <Input {...a11y} {...form.register('codigo_lc116')} />}
      </Campo>
      <Campo id="servico_ctribnac" label="cTribNac" dica="6 dígitos." erro={erros.c_trib_nac?.message}>
        {(a11y) => <Input {...a11y} {...form.register('c_trib_nac')} />}
      </Campo>
      <Campo id="servico_codmun" label="Código municipal" erro={erros.codigo_municipal?.message}>
        {(a11y) => <Input {...a11y} {...form.register('codigo_municipal')} />}
      </Campo>
      <Campo id="servico_iss" label="Alíquota de ISS (%)" erro={erros.aliquota_iss?.message}>
        {(a11y) => <Input {...a11y} {...form.register('aliquota_iss')} />}
      </Campo>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>Salvar serviço</Button>
    </form>
  );
}
