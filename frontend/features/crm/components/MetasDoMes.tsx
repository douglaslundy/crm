'use client';

import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { QUERY_KEY_USUARIOS, usuariosApi } from '@/features/usuarios/api';
import { formatarCentavos, reaisParaCentavos } from '@/lib/formatos';
import { useDefinirMeta, useMetas, useRemoverMeta } from '../hooks/useMetas';
import type { UnidadeDaMeta } from '../types';

export function MetasDoMes({ mes }: { mes: string | null }) {
  const metas = useMetas(mes);
  const definir = useDefinirMeta();
  const remover = useRemoverMeta();
  const usuarios = useQuery({ queryKey: QUERY_KEY_USUARIOS, queryFn: async () => (await usuariosApi.listar()).data });
  const [usuarioId, setUsuarioId] = useState('');
  const [unidade, setUnidade] = useState<UnidadeDaMeta>('VALOR');
  const [alvo, setAlvo] = useState('');

  if (mes === null) {
    return <Banner tom="info">Escolha um mês inteiro no filtro de período para definir metas.</Banner>;
  }

  const alvoNumerico = unidade === 'VALOR' ? reaisParaCentavos(alvo) : Number.parseInt(alvo, 10);
  const alvoValido = Number.isFinite(alvoNumerico) && alvoNumerico > 0;
  const ativos = (usuarios.data ?? []).filter((u) => u.ativo && u.papel !== 'FISCAL' && u.papel !== 'LEITURA');

  return (
    <Card>
      <CardHeader><CardTitle>Metas de {mes}</CardTitle></CardHeader>
      <CardContent className="space-y-4">
        {metas.isPending ? <p className="text-sm text-muted-foreground">Carregando...</p> : null}
        {metas.data && metas.data.length === 0 ? <p className="text-sm text-muted-foreground">Nenhuma meta definida neste mês.</p> : null}
        <ul className="divide-y divide-border text-sm">
          {(metas.data ?? []).map((m) => (
            <li key={m.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span>
                <strong>{m.usuario_nome ?? 'Usuário removido'}</strong>
                {' · '}
                {m.unidade === 'VALOR' ? formatarCentavos(m.alvo) : `${m.alvo} negócios ganhos`}
              </span>
              <Button size="sm" variant="outline" disabled={remover.isPending} onClick={() => remover.mutate(m.id)}>Remover</Button>
            </li>
          ))}
        </ul>

        <div className="grid gap-3 sm:grid-cols-3">
          <div className="space-y-1">
            <Label htmlFor="meta-vendedor">Vendedor</Label>
            <SelectNativo id="meta-vendedor" value={usuarioId} onChange={(e) => setUsuarioId(e.target.value)}>
              <option value="">Escolha...</option>
              {ativos.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}
            </SelectNativo>
          </div>
          <div className="space-y-1">
            <Label htmlFor="meta-unidade">Unidade</Label>
            <SelectNativo id="meta-unidade" value={unidade} onChange={(e) => setUnidade(e.target.value as UnidadeDaMeta)}>
              <option value="VALOR">Valor (R$)</option>
              <option value="QUANTIDADE">Quantidade de negócios</option>
            </SelectNativo>
          </div>
          <div className="space-y-1">
            <Label htmlFor="meta-alvo">Alvo</Label>
            <Input id="meta-alvo" inputMode="decimal" value={alvo} onChange={(e) => setAlvo(e.target.value)} />
          </div>
        </div>
        <Button
          disabled={usuarioId === '' || !alvoValido || definir.isPending}
          onClick={() => definir.mutate({ usuario_id: usuarioId, mes, unidade, alvo: alvoNumerico }, { onSuccess: () => setAlvo('') })}
        >
          Salvar meta
        </Button>
      </CardContent>
    </Card>
  );
}
