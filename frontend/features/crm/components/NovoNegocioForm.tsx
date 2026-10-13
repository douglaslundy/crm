'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm, useWatch } from 'react-hook-form';
import { toast } from 'sonner';
import { Campo } from '@/components/form/Campo';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { clientesApi, QUERY_KEY_CLIENTES } from '@/features/clientes/api';
import { ApiError } from '@/lib/api';
import { reaisParaCentavos } from '@/lib/formatos';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { crmApi } from '../api';
import { QUERY_KEY_NEGOCIOS } from '../hooks/useNegocios';
import { negocioSchema, type NegocioFormDados } from '../schemas';
import type { Funil, NovoNegocioPayload } from '../types';

const VALORES_INICIAIS: NegocioFormDados = {
  titulo: '', modo: 'NOVO', cliente_id: '', lead_nome: '', lead_telefone: '', lead_email: '',
  previsao_fechamento: '', valor_reais: '',
};

const CAMPOS_DA_API: Record<string, keyof NegocioFormDados> = {
  titulo: 'titulo', cliente_id: 'cliente_id', 'novo_lead.nome': 'lead_nome', 'novo_lead.telefone': 'lead_telefone',
  'novo_lead.email': 'lead_email', previsao_fechamento: 'previsao_fechamento', valor_centavos: 'valor_reais',
};

function montarPayload(d: NegocioFormDados, funil: Funil): NovoNegocioPayload | null {
  const primeira = [...funil.etapas].filter((e) => e.ativa).sort((a, b) => a.posicao - b.posicao)[0];
  if (!primeira) return null;

  const base: NovoNegocioPayload = { titulo: d.titulo, funil_id: funil.id, etapa_id: primeira.id };
  if (d.previsao_fechamento !== '') base.previsao_fechamento = d.previsao_fechamento;
  if (d.valor_reais !== '') base.valor_centavos = reaisParaCentavos(d.valor_reais);
  if (d.modo === 'EXISTENTE') return { ...base, cliente_id: d.cliente_id };

  const lead: NonNullable<NovoNegocioPayload['novo_lead']> = { nome: d.lead_nome };
  if (d.lead_telefone !== '') lead.telefone = d.lead_telefone;
  if (d.lead_email !== '') lead.email = d.lead_email;

  return { ...base, novo_lead: lead };
}

export function NovoNegocioForm({ funil, onCriado }: { funil: Funil; onCriado: () => void }) {
  const queryClient = useQueryClient();
  const form = useForm<NegocioFormDados>({ resolver: zodResolver(negocioSchema), defaultValues: VALORES_INICIAIS });
  const modo = useWatch({ control: form.control, name: 'modo' });
  const clientes = useQuery({
    queryKey: QUERY_KEY_CLIENTES,
    queryFn: async () => (await clientesApi.listar()).data,
    enabled: modo === 'EXISTENTE',
  });

  const criar = useMutation({
    mutationFn: (payload: NovoNegocioPayload) => crmApi.criarNegocio(payload),
    onSuccess: () => {
      toast.success('Negócio criado.');
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_NEGOCIOS });
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_CLIENTES });
      onCriado();
    },
    onError: (erro) => {
      if (!(erro instanceof ApiError)) {
        form.setError('root', { message: 'Não foi possível criar o negócio. Tente novamente.' });
        return;
      }
      let mapeado = false;
      for (const [campo, mensagens] of Object.entries(erro.errors)) {
        const destino = CAMPOS_DA_API[campo];
        if (destino) {
          form.setError(destino, { message: mensagens[0] });
          mapeado = true;
        }
      }
      if (!mapeado) form.setError('root', { message: erro.message });
    },
  });

  const envioUnico = useEnvioUnico();
  const enviar = envioUnico(
    form.handleSubmit(async (d) => {
      const payload = montarPayload(d, funil);
      if (!payload) {
        form.setError('root', { message: 'Este funil não tem etapa ativa.' });
        return;
      }
      await criar.mutateAsync(payload).catch(() => undefined);
    }),
  );
  const erros = form.formState.errors;

  return (
    <form onSubmit={enviar} noValidate className="space-y-3 rounded-lg border border-border bg-card p-4">
      <Campo id="titulo" label="Título" erro={erros.titulo?.message}>
        {(a11y) => <Input {...a11y} {...form.register('titulo')} />}
      </Campo>

      <fieldset className="flex gap-4 text-sm">
        <legend className="sr-only">Cliente</legend>
        <label className="flex items-center gap-1.5">
          <input type="radio" value="NOVO" {...form.register('modo')} /> Novo lead
        </label>
        <label className="flex items-center gap-1.5">
          <input type="radio" value="EXISTENTE" {...form.register('modo')} /> Cliente existente
        </label>
      </fieldset>

      {modo === 'NOVO' ? (
        <>
          <Campo id="lead_nome" label="Nome do lead" erro={erros.lead_nome?.message}>
            {(a11y) => <Input {...a11y} autoComplete="off" {...form.register('lead_nome')} />}
          </Campo>
          <Campo id="lead_telefone" label="Telefone do lead" erro={erros.lead_telefone?.message}>
            {(a11y) => <Input {...a11y} autoComplete="off" {...form.register('lead_telefone')} />}
          </Campo>
          <Campo id="lead_email" label="E-mail do lead" erro={erros.lead_email?.message}>
            {(a11y) => <Input {...a11y} type="email" autoComplete="off" {...form.register('lead_email')} />}
          </Campo>
        </>
      ) : (
        <Campo id="cliente_id" label="Cliente" erro={erros.cliente_id?.message} obrigatorio>
          {(a11y) => (
            <select {...a11y} className="w-full rounded-md border border-border bg-background px-2 py-2 text-sm" {...form.register('cliente_id')}>
              <option value="">Escolha...</option>
              {(clientes.data ?? []).map((c) => (
                <option key={c.id} value={c.id}>{c.nome}</option>
              ))}
            </select>
          )}
        </Campo>
      )}

      <div className="grid gap-3 sm:grid-cols-2">
        <Campo id="previsao_fechamento" label="Previsão de fechamento" erro={erros.previsao_fechamento?.message}>
          {(a11y) => <Input {...a11y} type="date" {...form.register('previsao_fechamento')} />}
        </Campo>
        <Campo id="valor_reais" label="Valor (R$)" erro={erros.valor_reais?.message} dica="Ex.: 1500,00">
          {(a11y) => <Input {...a11y} inputMode="decimal" {...form.register('valor_reais')} />}
        </Campo>
      </div>

      {erros.root ? <Banner tom="perigo">{erros.root.message}</Banner> : null}
      <Button type="submit" disabled={form.formState.isSubmitting}>Criar negócio</Button>
    </form>
  );
}
