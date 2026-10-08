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
import { formatarData } from '@/lib/formatos';
import { useEnvioUnico } from '@/lib/useEnvioUnico';
import { chavesApi, QUERY_KEY_CHAVES_API } from '../api';
import { chaveSchema, type ChaveDados } from '../schemas';
import { ROTULO_AMBIENTE, type ChaveCriada, type ChaveDeApi } from '../types';

const VAZIO: ChaveDados = { nome: '', ambiente: 'TEST' };

function mensagemDoErro(erro: unknown, padrao: string): string {
  return erro instanceof ApiError ? erro.primeiraMensagem() : padrao;
}

/** Guarda de papel: sem permissão não monta nada (e, portanto, não chama a API). */
export function ChavesDeApi() {
  const { data: usuario } = useUsuario();

  if (!usuario) return null;
  if (!podeGerenciar(usuario.papel)) {
    return <Banner tom="perigo">Você não tem permissão para gerenciar chaves de API.</Banner>;
  }

  return <PainelDeChaves />;
}

function PainelDeChaves() {
  const queryClient = useQueryClient();
  // A chave inteira vive só neste estado: nada de cache de query nem storage; some ao fechar o aviso.
  const [criada, setCriada] = useState<ChaveCriada | null>(null);
  const [erro, setErro] = useState<string | null>(null);
  const [confirmando, setConfirmando] = useState<string | null>(null);
  const [copiada, setCopiada] = useState(false);
  const envioUnico = useEnvioUnico();
  const form = useForm<ChaveDados>({ resolver: zodResolver(chaveSchema), defaultValues: VAZIO });
  const erros = form.formState.errors;

  const lista = useQuery({ queryKey: QUERY_KEY_CHAVES_API, queryFn: async () => (await chavesApi.listar()).data });

  const criar = useMutation({
    mutationFn: (dados: ChaveDados) => chavesApi.criar(dados),
    onSuccess: ({ data }) => {
      setErro(null);
      setCopiada(false);
      setCriada(data);
      criar.reset(); // a resposta (com a chave) não fica no cache de mutações
      form.reset(VAZIO);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_CHAVES_API });
    },
    onError: (e) => setErro(mensagemDoErro(e, 'Não foi possível criar a chave. Tente novamente.')),
  });

  const revogar = useMutation({
    mutationFn: (id: string) => chavesApi.revogar(id),
    onSuccess: () => {
      setErro(null);
      setConfirmando(null);
      void queryClient.invalidateQueries({ queryKey: QUERY_KEY_CHAVES_API });
    },
    onError: (e) => {
      setConfirmando(null);
      setErro(mensagemDoErro(e, 'Não foi possível revogar a chave. Tente novamente.'));
    },
  });

  async function copiar(texto: string) {
    try {
      await navigator.clipboard.writeText(texto);
      setCopiada(true);
    } catch {
      setErro('Não foi possível copiar. Selecione a chave e copie manualmente.');
    }
  }

  return (
    <div className="space-y-4">
      <h1 className="font-display text-[28px] leading-tight font-extrabold">Chaves de API</h1>
      {erro !== null ? <Banner tom="perigo">{erro}</Banner> : null}

      {criada !== null ? (
        <Banner tom="alerta" rotulo="Chave criada">
          <p className="mb-1">Copie agora: ela não será mostrada de novo.</p>
          <code className="block rounded bg-background px-2 py-1 font-mono text-sm break-all text-foreground">{criada.chave}</code>
          <div className="mt-2 flex gap-2">
            <Button type="button" size="sm" onClick={() => void copiar(criada.chave)}>{copiada ? 'Copiada' : 'Copiar chave'}</Button>
            <Button type="button" size="sm" variant="outline" onClick={() => setCriada(null)}>Fechar aviso</Button>
          </div>
        </Banner>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <Card>
          <CardHeader><CardTitle>Suas chaves</CardTitle></CardHeader>
          <CardContent>
            {lista.isPending ? <p className="text-muted-foreground">Carregando...</p> : null}
            {lista.isError ? <Banner tom="perigo">Não foi possível carregar as chaves.</Banner> : null}
            {lista.data ? (
              lista.data.length === 0 ? (
                <p className="text-sm text-muted-foreground">Nenhuma chave criada ainda.</p>
              ) : (
                <ul className="divide-y">
                  {lista.data.map((chave) => (
                    <LinhaDaChave
                      key={chave.id}
                      chave={chave}
                      confirmando={confirmando === chave.id}
                      ocupado={revogar.isPending}
                      onPedirRevogacao={() => setConfirmando(chave.id)}
                      onCancelar={() => setConfirmando(null)}
                      onConfirmar={() => revogar.mutate(chave.id)}
                    />
                  ))}
                </ul>
              )
            ) : null}
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Nova chave</CardTitle></CardHeader>
          <CardContent>
            <form
              noValidate
              className="space-y-3"
              onSubmit={envioUnico(form.handleSubmit(async (dados) => { await criar.mutateAsync(dados).catch(() => undefined); }))}
            >
              <Campo id="chave_nome" label="Nome" erro={erros.nome?.message}>
                {(a11y) => <Input {...a11y} autoComplete="off" placeholder="Ex.: ERP da loja" {...form.register('nome')} />}
              </Campo>
              <Campo id="chave_ambiente" label="Ambiente" erro={erros.ambiente?.message} dica="A chave de teste emite notas em homologação.">
                {(a11y) => (
                  <SelectNativo {...a11y} {...form.register('ambiente')}>
                    <option value="TEST">{ROTULO_AMBIENTE.TEST}</option>
                    <option value="LIVE">{ROTULO_AMBIENTE.LIVE}</option>
                  </SelectNativo>
                )}
              </Campo>
              <Button type="submit" className="w-full" disabled={form.formState.isSubmitting}>
                {form.formState.isSubmitting ? 'Criando...' : 'Criar chave'}
              </Button>
              <p className="text-xs text-muted-foreground">A chave age como você: as ações feitas por ela ficam registradas no seu nome.</p>
            </form>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}

interface LinhaProps {
  chave: ChaveDeApi;
  confirmando: boolean;
  ocupado: boolean;
  onPedirRevogacao: () => void;
  onCancelar: () => void;
  onConfirmar: () => void;
}

function LinhaDaChave({ chave, confirmando, ocupado, onPedirRevogacao, onCancelar, onConfirmar }: LinhaProps) {
  const revogada = chave.revogada_em !== null;

  return (
    <li className="py-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="min-w-0">
          <p className="font-medium">
            {chave.nome}
            {revogada ? <span className="ml-2 rounded bg-muted px-1.5 py-0.5 text-xs font-semibold text-muted-foreground">Revogada</span> : null}
          </p>
          <p className="text-sm text-muted-foreground">
            <span className="font-mono">{chave.prefixo}…{chave.ultimos4}</span> · {ROTULO_AMBIENTE[chave.ambiente]} · criada por {chave.usuario.nome}
          </p>
          <p className="text-xs text-muted-foreground">
            {chave.ultimo_uso_em === null ? 'Nunca usada' : `Último uso: ${formatarData(chave.ultimo_uso_em)}`}
          </p>
        </div>
        {revogada ? null : (
          <Button type="button" variant="outline" size="sm" aria-label={`Revogar ${chave.nome}`} disabled={ocupado} onClick={onPedirRevogacao}>Revogar</Button>
        )}
      </div>
      {confirmando ? (
        <div className="mt-2 flex flex-wrap items-center gap-2 text-sm">
          <span>Integrações que usam esta chave deixam de funcionar.</span>
          <Button type="button" size="sm" disabled={ocupado} onClick={onConfirmar}>Confirmar revogação</Button>
          <Button type="button" size="sm" variant="outline" onClick={onCancelar}>Cancelar</Button>
        </div>
      ) : null}
    </li>
  );
}
