'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Campo } from '@/components/form/Campo';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { podeGerenciar } from '@/features/usuarios/types';
import { ApiError } from '@/lib/api';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { QUERY_KEY_WEBHOOKS, webhooksApi } from '../api';
import { webhookSchema, type WebhookDados } from '../schemas';
import { EVENTOS_DE_NOTA, ROTULO_AMBIENTE, type Entrega, type Webhook, type WebhookCriado } from '../types';
import { EntregasDoWebhook } from './EntregasDoWebhook';

const VAZIO: WebhookDados = { url: '', descricao: '', ambiente: 'HOMOLOGACAO', eventos: [] };

function mensagemDoErro(erro: unknown, padrao: string): string {
  return erro instanceof ApiError ? erro.primeiraMensagem() : padrao;
}

function rotuloDosEventos(eventos: string[]): string {
  if (eventos.length === 0) return 'Todos os eventos de nota';
  return eventos.map((e) => EVENTOS_DE_NOTA.find((n) => n.valor === e)?.rotulo ?? e).join(', ');
}

/** Guarda de papel: sem permissão não monta nada (e, portanto, não chama a API). */
export function Webhooks() {
  const { data: usuario } = useUsuario();

  if (!usuario) return null;
  if (!podeGerenciar(usuario.papel)) {
    return <Banner tom="perigo">Você não tem permissão para gerenciar webhooks.</Banner>;
  }

  return <PainelDeWebhooks />;
}

function PainelDeWebhooks() {
  const queryClient = useQueryClient();
  // O segredo inteiro vive só neste estado: nada de cache de query nem storage; some ao fechar o aviso.
  const [criado, setCriado] = useState<WebhookCriado | null>(null);
  const [erro, setErro] = useState<string | null>(null);
  const [confirmando, setConfirmando] = useState<string | null>(null);
  const [aberto, setAberto] = useState<string | null>(null);
  const [copiado, setCopiado] = useState(false);
  const [resultadoDoTeste, setResultadoDoTeste] = useState<Entrega | null>(null);
  const envioUnico = useEnvioUnico();
  const form = useForm<WebhookDados>({ resolver: zodResolver(webhookSchema), defaultValues: VAZIO });
  const erros = form.formState.errors;

  const lista = useQuery({ queryKey: QUERY_KEY_WEBHOOKS, queryFn: async () => (await webhooksApi.listar()).data });
  const recarregar = () => void queryClient.invalidateQueries({ queryKey: QUERY_KEY_WEBHOOKS });

  const criar = useMutation({
    mutationFn: (dados: WebhookDados) => webhooksApi.criar(dados),
    onSuccess: ({ data }) => {
      setErro(null);
      setCopiado(false);
      setCriado(data);
      criar.reset(); // a resposta (com o segredo) não fica no cache de mutações
      form.reset(VAZIO);
      recarregar();
    },
    onError: (e) => setErro(mensagemDoErro(e, 'Não foi possível criar o webhook. Tente novamente.')),
  });

  const alternar = useMutation({
    mutationFn: ({ id, ativo }: { id: string; ativo: boolean }) => webhooksApi.atualizar(id, { ativo }),
    onSuccess: () => {
      setErro(null);
      recarregar();
    },
    onError: (e) => setErro(mensagemDoErro(e, 'Não foi possível alterar o webhook. Tente novamente.')),
  });

  const excluir = useMutation({
    mutationFn: (id: string) => webhooksApi.excluir(id),
    onSuccess: (_, id) => {
      setErro(null);
      setConfirmando(null);
      setAberto((atual) => (atual === id ? null : atual));
      recarregar();
    },
    onError: (e) => {
      setConfirmando(null);
      setErro(mensagemDoErro(e, 'Não foi possível excluir o webhook. Tente novamente.'));
    },
  });

  const testar = useMutation({
    mutationFn: (id: string) => webhooksApi.testar(id),
    onSuccess: ({ data }) => {
      setErro(null);
      setResultadoDoTeste(data);
      void queryClient.invalidateQueries({ queryKey: [...QUERY_KEY_WEBHOOKS, data.webhook_id, 'entregas'] });
    },
    onError: (e) => {
      setResultadoDoTeste(null);
      setErro(mensagemDoErro(e, 'Não foi possível enviar o teste. Tente novamente.'));
    },
  });

  async function copiar(texto: string) {
    try {
      await navigator.clipboard.writeText(texto);
      setCopiado(true);
    } catch {
      setErro('Não foi possível copiar. Selecione o segredo e copie manualmente.');
    }
  }

  const ocupado = alternar.isPending || excluir.isPending || testar.isPending;

  return (
    <div className="space-y-4">
      <h1 className="font-display text-[28px] leading-tight font-extrabold">Webhooks</h1>
      <p className="max-w-3xl text-sm text-muted-foreground">
        Avisamos o seu sistema quando uma nota é autorizada, rejeitada, cancelada, tem erro ou entra em contingência. Cada aviso é assinado com o segredo do webhook.
      </p>
      {erro !== null ? <Banner tom="perigo">{erro}</Banner> : null}

      {criado !== null ? (
        <Banner tom="alerta" rotulo="Webhook criado">
          <p className="mb-1">Copie agora: ele não será mostrado de novo. Use-o para conferir a assinatura (X-Signature) de cada aviso.</p>
          <code className="block rounded bg-background px-2 py-1 font-mono text-sm break-all text-foreground">{criado.segredo}</code>
          <div className="mt-2 flex gap-2">
            <Button type="button" size="sm" onClick={() => void copiar(criado.segredo)}>{copiado ? 'Copiado' : 'Copiar segredo'}</Button>
            <Button type="button" size="sm" variant="outline" onClick={() => setCriado(null)}>Fechar aviso</Button>
          </div>
        </Banner>
      ) : null}

      {resultadoDoTeste !== null ? <ResultadoDoTeste entrega={resultadoDoTeste} onFechar={() => setResultadoDoTeste(null)} /> : null}

      <div className="grid gap-4 lg:grid-cols-[1fr_24rem]">
        <Card>
          <CardHeader><CardTitle>Seus webhooks</CardTitle></CardHeader>
          <CardContent>
            {lista.isPending ? <p className="text-muted-foreground">Carregando...</p> : null}
            {lista.isError ? <Banner tom="perigo">Não foi possível carregar os webhooks.</Banner> : null}
            {lista.data ? (
              lista.data.length === 0 ? (
                <p className="text-sm text-muted-foreground">Nenhum webhook criado ainda.</p>
              ) : (
                <ul className="divide-y">
                  {lista.data.map((webhook) => (
                    <LinhaDoWebhook
                      key={webhook.id}
                      webhook={webhook}
                      ocupado={ocupado}
                      confirmando={confirmando === webhook.id}
                      aberto={aberto === webhook.id}
                      onAlternar={() => alternar.mutate({ id: webhook.id, ativo: !webhook.ativo })}
                      onTestar={() => testar.mutate(webhook.id)}
                      onAbrir={() => setAberto(aberto === webhook.id ? null : webhook.id)}
                      onPedirExclusao={() => setConfirmando(webhook.id)}
                      onCancelar={() => setConfirmando(null)}
                      onConfirmar={() => excluir.mutate(webhook.id)}
                    />
                  ))}
                </ul>
              )
            ) : null}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Novo webhook</CardTitle></CardHeader>
          <CardContent>
            <form
              noValidate
              className="space-y-3"
              onSubmit={envioUnico(form.handleSubmit(async (dados) => { await criar.mutateAsync(dados).catch(() => undefined); }))}
            >
              <Campo id="webhook_url" label="URL" erro={erros.url?.message} dica="Precisa ser https e acessível pela internet.">
                {(a11y) => <Input {...a11y} type="url" autoComplete="off" placeholder="https://seu-sistema.com/webhooks/fiscal" {...form.register('url')} />}
              </Campo>
              <Campo id="webhook_descricao" label="Descrição (opcional)" erro={erros.descricao?.message}>
                {(a11y) => <Input {...a11y} autoComplete="off" placeholder="Ex.: ERP da loja" {...form.register('descricao')} />}
              </Campo>
              <Campo id="webhook_ambiente" label="Ambiente" erro={erros.ambiente?.message} dica="Só notas deste ambiente geram avisos.">
                {(a11y) => (
                  <SelectNativo {...a11y} {...form.register('ambiente')}>
                    <option value="HOMOLOGACAO">{ROTULO_AMBIENTE.HOMOLOGACAO}</option>
                    <option value="PRODUCAO">{ROTULO_AMBIENTE.PRODUCAO}</option>
                  </SelectNativo>
                )}
              </Campo>
              <fieldset className="space-y-1">
                <legend className="text-sm font-medium">Eventos</legend>
                <p className="text-xs text-muted-foreground">Sem nenhum marcado, você recebe todos.</p>
                {EVENTOS_DE_NOTA.map((evento) => (
                  <label key={evento.valor} className="flex items-center gap-2 text-sm">
                    <input type="checkbox" value={evento.valor} {...form.register('eventos')} />
                    {evento.rotulo}
                  </label>
                ))}
              </fieldset>
              <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>
                {form.formState.isSubmitting ? 'Criando...' : 'Criar webhook'}
              </Button>
              <p className="text-xs text-muted-foreground">Até 5 webhooks por empresa. Entregamos pelo menos uma vez: use o id do evento para ignorar repetições.</p>
            </form>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}

function ResultadoDoTeste({ entrega, onFechar }: { entrega: Entrega; onFechar: () => void }) {
  const ultima = entrega.tentativas_log[entrega.tentativas_log.length - 1];
  const http = ultima?.status_http != null ? `HTTP ${ultima.status_http}` : null;

  if (entrega.estado === 'ENTREGUE') {
    return (
      <Banner tom="sucesso" onDispensar={onFechar}>Teste entregue{http !== null ? ` (${http})` : ''}.</Banner>
    );
  }

  return (
    <Banner tom="perigo" onDispensar={onFechar}>
      O teste falhou{http !== null ? ` (${http})` : ''}.{ultima?.erro ? ` ${ultima.erro}` : ''}
    </Banner>
  );
}

interface LinhaProps {
  webhook: Webhook;
  ocupado: boolean;
  confirmando: boolean;
  aberto: boolean;
  onAlternar: () => void;
  onTestar: () => void;
  onAbrir: () => void;
  onPedirExclusao: () => void;
  onCancelar: () => void;
  onConfirmar: () => void;
}

function LinhaDoWebhook({ webhook, ocupado, confirmando, aberto, onAlternar, onTestar, onAbrir, onPedirExclusao, onCancelar, onConfirmar }: LinhaProps) {
  return (
    <li className="py-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="font-medium break-all">
            {webhook.url}
            <span className={`ml-2 rounded px-1.5 py-0.5 text-xs font-semibold ${webhook.ativo ? 'bg-success/10 text-success' : 'bg-muted text-muted-foreground'}`}>
              {webhook.ativo ? 'Ativo' : 'Desativado'}
            </span>
          </p>
          <p className="text-sm text-muted-foreground">
            {webhook.descricao !== null && webhook.descricao !== '' ? `${webhook.descricao} · ` : ''}
            {ROTULO_AMBIENTE[webhook.ambiente]} · {rotuloDosEventos(webhook.eventos)}
          </p>
          <p className="text-xs text-muted-foreground">Segredo terminado em <span className="font-mono">{webhook.segredo_final}</span></p>
          {!webhook.ativo && webhook.motivo_desativacao !== null ? <p className="text-xs text-danger">{webhook.motivo_desativacao}</p> : null}
        </div>
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="outline" size="sm" aria-label={`Enviar teste para ${webhook.url}`} disabled={ocupado || !webhook.ativo} onClick={onTestar}>Enviar teste</Button>
          <Button type="button" variant="outline" size="sm" aria-label={`${webhook.ativo ? 'Desativar' : 'Ativar'} ${webhook.url}`} disabled={ocupado} onClick={onAlternar}>
            {webhook.ativo ? 'Desativar' : 'Ativar'}
          </Button>
          <Button type="button" variant="outline" size="sm" aria-label={`Entregas de ${webhook.url}`} aria-expanded={aberto} onClick={onAbrir}>Entregas</Button>
          <Button type="button" variant="outline" size="sm" aria-label={`Excluir ${webhook.url}`} disabled={ocupado} onClick={onPedirExclusao}>Excluir</Button>
        </div>
      </div>
      {confirmando ? (
        <div className="mt-2 flex flex-wrap items-center gap-2 text-sm">
          <span>O histórico de entregas também será apagado.</span>
          <Button type="button" size="sm" disabled={ocupado} onClick={onConfirmar}>Confirmar exclusão</Button>
          <Button type="button" size="sm" variant="outline" onClick={onCancelar}>Cancelar</Button>
        </div>
      ) : null}
      {aberto ? <EntregasDoWebhook webhookId={webhook.id} /> : null}
    </li>
  );
}
