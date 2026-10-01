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
import { paraPayload, produtosApi, QUERY_KEY_PRODUTOS } from '../api';
import { produtoSchema, type ProdutoFormDados } from '../schemas';
import type { Produto } from '../types';

const VAZIO: ProdutoFormDados = { sku: '', nome: '', unidade: '', preco_centavos: 0, gtin: '', ncm: '', cest: '', origem: '', tributacao_icms: '' };

function paraFormulario(p: Produto): ProdutoFormDados {
  return {
    sku: p.sku, nome: p.nome, unidade: p.unidade, preco_centavos: p.preco_centavos,
    gtin: p.gtin ?? '', ncm: p.ncm ?? '', cest: p.cest ?? '',
    origem: p.origem === null ? '' : String(p.origem),
    tributacao_icms: p.tributacao_icms ?? '',
  };
}

export function ProdutoForm({ produto, onSalvar }: { produto?: Produto; onSalvar?: () => void }) {
  const queryClient = useQueryClient();
  const form = useForm<ProdutoFormDados>({ resolver: zodResolver(produtoSchema), defaultValues: produto ? paraFormulario(produto) : VAZIO });
  const salvar = useMutation({
    mutationFn: (d: ProdutoFormDados) => (produto ? produtosApi.editar(produto.id, paraPayload(d)) : produtosApi.criar(paraPayload(d))),
    onSuccess: () => {
      toast.success(produto ? 'Produto atualizado.' : 'Produto criado.');
      if (!produto) form.reset(VAZIO);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_PRODUTOS });
      onSalvar?.();
    },
    onError: (erro) => {
      if (!(erro instanceof ApiError)) {
        form.setError('root', { message: 'Não foi possível salvar. Tente novamente.' });
        return;
      }
      form.setError('root', { message: erro.primeiraMensagem() });
    },
  });
  const envioUnico = useEnvioUnico();
  const erros = form.formState.errors;

  return (
    <form noValidate className="space-y-3" onSubmit={envioUnico(form.handleSubmit(async (d) => { await salvar.mutateAsync(d).catch(() => undefined); }))}>
      <Campo id="produto_sku" label="SKU" erro={erros.sku?.message}>
        {(a11y) => <Input {...a11y} {...form.register('sku')} />}
      </Campo>
      <Campo id="produto_nome" label="Nome" erro={erros.nome?.message}>
        {(a11y) => <Input {...a11y} {...form.register('nome')} />}
      </Campo>
      <Campo id="produto_unidade" label="Unidade" erro={erros.unidade?.message}>
        {(a11y) => <Input {...a11y} {...form.register('unidade')} />}
      </Campo>
      <Campo id="produto_preco" label="Preço (centavos)" erro={erros.preco_centavos?.message}>
        {(a11y) => <Input {...a11y} type="number" min={0} {...form.register('preco_centavos', { valueAsNumber: true })} />}
      </Campo>
      <Campo id="produto_ncm" label="NCM" dica="8 dígitos. Deixe em branco se não souber ainda." erro={erros.ncm?.message}>
        {(a11y) => <Input {...a11y} {...form.register('ncm')} />}
      </Campo>
      <Campo id="produto_cest" label="CEST" erro={erros.cest?.message}>
        {(a11y) => <Input {...a11y} {...form.register('cest')} />}
      </Campo>
      <Campo id="produto_origem" label="Origem" dica="0 a 8. Zero (nacional) é um valor válido." erro={erros.origem?.message}>
        {(a11y) => <Input {...a11y} {...form.register('origem')} />}
      </Campo>
      <Campo id="produto_tributacao" label="Tributação de ICMS" erro={erros.tributacao_icms?.message}>
        {(a11y) => (
          <SelectNativo {...a11y} {...form.register('tributacao_icms')}>
            <option value="">Não informado</option>
            <option value="NORMAL">Normal</option>
            <option value="ST">Substituição tributária</option>
          </SelectNativo>
        )}
      </Campo>
      {erros.root ? <p role="alert" className="rounded-md bg-danger/10 p-2 text-sm text-danger">{erros.root.message}</p> : null}
      <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>Salvar produto</Button>
    </form>
  );
}
