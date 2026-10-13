'use client';

import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ApiError } from '@/lib/api';
import { crmApi } from '../api';
import { QUERY_KEY_FUNIS, useFunis } from '../hooks/useFunis';
import type { Etapa, Funil } from '../types';

function NovaEtapa({ funil, aoCriar }: { funil: Funil; aoCriar: (nome: string, probabilidade: number) => void }) {
  const [nome, setNome] = useState('');
  const [probabilidade, setProbabilidade] = useState('0');

  return (
    <div className="flex flex-wrap items-end gap-2 pt-2">
      <div className="space-y-1">
        <Label htmlFor={`nova-etapa-${funil.id}`}>Nova etapa</Label>
        <Input id={`nova-etapa-${funil.id}`} value={nome} maxLength={100} onChange={(e) => setNome(e.target.value)} />
      </div>
      <div className="w-24 space-y-1">
        <Label htmlFor={`prob-nova-${funil.id}`}>Probabilidade (%)</Label>
        <Input id={`prob-nova-${funil.id}`} type="number" min={0} max={100} value={probabilidade} onChange={(e) => setProbabilidade(e.target.value)} />
      </div>
      <Button
        type="button"
        variant="outline"
        disabled={nome.trim() === ''}
        aria-label={`Adicionar etapa em ${funil.nome}`}
        onClick={() => {
          aoCriar(nome.trim(), Number(probabilidade) || 0);
          setNome('');
          setProbabilidade('0');
        }}
      >
        Adicionar etapa
      </Button>
    </div>
  );
}

export function EditorDeFunis({ podeEditar }: { podeEditar: boolean }) {
  const queryClient = useQueryClient();
  const [novoFunil, setNovoFunil] = useState('');
  const { data, isPending, isError } = useFunis();
  const invalida = () => queryClient.invalidateQueries({ queryKey: QUERY_KEY_FUNIS });
  const aoErro = (erro: unknown) => toast.error(erro instanceof ApiError ? erro.message : 'Tente novamente.');
  const opcoes = { onSuccess: () => void invalida(), onError: aoErro };

  const ordenar = useMutation({ mutationFn: ({ funilId, ids }: { funilId: string; ids: string[] }) => crmApi.ordenarEtapas(funilId, ids), ...opcoes });
  const editarEtapa = useMutation({
    mutationFn: ({ id, dados }: { id: string; dados: { probabilidade?: number; ativa?: boolean; dias_para_parado?: number | null } }) => crmApi.editarEtapa(id, dados),
    ...opcoes,
  });
  const criarEtapa = useMutation({
    mutationFn: ({ funilId, nome, probabilidade }: { funilId: string; nome: string; probabilidade: number }) =>
      crmApi.criarEtapa(funilId, { nome, probabilidade }),
    ...opcoes,
  });
  const criarFunil = useMutation({
    mutationFn: (nome: string) => crmApi.criarFunil(nome),
    onSuccess: () => {
      setNovoFunil('');
      void invalida();
    },
    onError: aoErro,
  });
  const editarFunil = useMutation({
    mutationFn: ({ id, ativo }: { id: string; ativo: boolean }) => crmApi.editarFunil(id, { ativo }),
    ...opcoes,
  });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <Banner tom="perigo">Não foi possível carregar os funis.</Banner>;

  function mover(funil: Funil, etapa: Etapa, delta: -1 | 1) {
    const ids = [...funil.etapas].sort((a, b) => a.posicao - b.posicao).map((e) => e.id);
    const de = ids.indexOf(etapa.id);
    const para = de + delta;
    if (para < 0 || para >= ids.length) return;
    [ids[de], ids[para]] = [ids[para], ids[de]];
    ordenar.mutate({ funilId: funil.id, ids });
  }

  return (
    <div className="space-y-6">
      {data.map((funil) => {
        const etapas = [...funil.etapas].sort((a, b) => a.posicao - b.posicao);

        return (
          <section key={funil.id} aria-label={`Funil ${funil.nome}`} className="space-y-2">
            <div className="flex items-center justify-between gap-2">
              <h3 className="font-semibold">
                {funil.nome}
                {funil.padrao ? <span className="ml-2 text-xs text-muted-foreground">(padrão)</span> : null}
                {!funil.ativo ? <span className="ml-2 text-xs text-muted-foreground">(inativo)</span> : null}
              </h3>
              {podeEditar && !funil.padrao ? (
                <Button
                  variant="outline"
                  size="sm"
                  aria-label={`${funil.ativo ? 'Desativar' : 'Ativar'} funil ${funil.nome}`}
                  onClick={() => editarFunil.mutate({ id: funil.id, ativo: !funil.ativo })}
                >
                  {funil.ativo ? 'Desativar funil' : 'Ativar funil'}
                </Button>
              ) : null}
            </div>
            <ul className="divide-y">
              {etapas.map((etapa, i) => (
                <li key={etapa.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                  <span className={etapa.ativa ? '' : 'text-muted-foreground line-through'}>{etapa.nome}</span>
                  <span className="text-sm text-muted-foreground">{etapa.probabilidade}%</span>
                  {podeEditar ? (
                    <div className="flex items-center gap-1">
                      <Input
                        type="number"
                        min={0}
                        max={100}
                        className="h-8 w-20"
                        aria-label={`Probabilidade de ${etapa.nome}`}
                        defaultValue={etapa.probabilidade}
                        onBlur={(e) => {
                          const valor = Number(e.target.value);
                          if (!Number.isNaN(valor) && valor !== etapa.probabilidade) {
                            editarEtapa.mutate({ id: etapa.id, dados: { probabilidade: valor } });
                          }
                        }}
                      />
                      <Input
                        type="number"
                        min={1}
                        max={365}
                        className="h-8 w-20"
                        placeholder="Dias"
                        aria-label={`Dias para parado de ${etapa.nome}`}
                        defaultValue={etapa.dias_para_parado ?? ''}
                        onBlur={(e) => {
                          const texto = e.target.value.trim();
                          const valor = texto === '' ? null : Number(texto);
                          if (valor !== null && (Number.isNaN(valor) || valor < 1 || valor > 365)) return;
                          if (valor !== etapa.dias_para_parado) {
                            editarEtapa.mutate({ id: etapa.id, dados: { dias_para_parado: valor } });
                          }
                        }}
                      />
                      <Button variant="outline" size="icon-sm" aria-label={`Subir ${etapa.nome}`} disabled={i === 0} onClick={() => mover(funil, etapa, -1)}>
                        <ArrowUp aria-hidden />
                      </Button>
                      <Button variant="outline" size="icon-sm" aria-label={`Descer ${etapa.nome}`} disabled={i === etapas.length - 1} onClick={() => mover(funil, etapa, 1)}>
                        <ArrowDown aria-hidden />
                      </Button>
                      <Button
                        variant="outline"
                        size="sm"
                        aria-label={`${etapa.ativa ? 'Desativar' : 'Ativar'} etapa ${etapa.nome}`}
                        onClick={() => editarEtapa.mutate({ id: etapa.id, dados: { ativa: !etapa.ativa } })}
                      >
                        {etapa.ativa ? 'Desativar' : 'Ativar'}
                      </Button>
                    </div>
                  ) : null}
                </li>
              ))}
            </ul>
            {podeEditar ? (
              <NovaEtapa funil={funil} aoCriar={(nome, probabilidade) => criarEtapa.mutate({ funilId: funil.id, nome, probabilidade })} />
            ) : null}
          </section>
        );
      })}

      {podeEditar ? (
        <div className="flex items-end gap-2 border-t pt-4">
          <div className="flex-1 space-y-1">
            <Label htmlFor="novo-funil">Novo funil</Label>
            <Input id="novo-funil" value={novoFunil} maxLength={100} onChange={(e) => setNovoFunil(e.target.value)} />
          </div>
          <Button type="button" disabled={criarFunil.isPending || novoFunil.trim() === ''} onClick={() => criarFunil.mutate(novoFunil.trim())}>
            Criar funil
          </Button>
        </div>
      ) : null}
    </div>
  );
}
