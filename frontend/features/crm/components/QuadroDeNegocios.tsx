'use client';

import { useState } from 'react';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { useFunis } from '../hooks/useFunis';
import { useNegocios } from '../hooks/useNegocios';
import type { StatusDoNegocio } from '../types';
import { KanbanDeNegocios } from './KanbanDeNegocios';
import { NovoNegocioForm } from './NovoNegocioForm';
import { TabelaDeFechados } from './TabelaDeFechados';

const PAPEIS_QUE_ESCREVEM = ['PROPRIETARIO', 'ADMIN', 'VENDEDOR'];

const ABAS: { status: StatusDoNegocio; rotulo: string }[] = [
  { status: 'ABERTO', rotulo: 'Abertos' },
  { status: 'GANHO', rotulo: 'Ganhos' },
  { status: 'PERDIDO', rotulo: 'Perdidos' },
];

export function QuadroDeNegocios() {
  const { data: usuario } = useUsuario();
  const podeEscrever = Boolean(usuario && PAPEIS_QUE_ESCREVEM.includes(usuario.papel));
  const [status, setStatus] = useState<StatusDoNegocio>('ABERTO');
  const [funilEscolhido, setFunilEscolhido] = useState<string | null>(null);
  const [criando, setCriando] = useState(false);
  const [soParados, setSoParados] = useState(false);

  const funis = useFunis();
  const ativos = (funis.data ?? []).filter((f) => f.ativo);
  const funil = ativos.find((f) => f.id === funilEscolhido) ?? ativos[0];
  const negocios = useNegocios(funil?.id, status);

  if (funis.isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (funis.isError) return <Banner tom="perigo">{funis.error.message || 'Não foi possível carregar o CRM.'}</Banner>;
  if (!funil) return <Banner tom="alerta">Nenhum funil ativo. Peça a um administrador para configurar o CRM.</Banner>;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div role="tablist" aria-label="Situação dos negócios" className="flex gap-1">
          {ABAS.map((aba) => (
            <button
              key={aba.status}
              role="tab"
              type="button"
              aria-selected={status === aba.status}
              onClick={() => setStatus(aba.status)}
              className={`rounded-md px-3 py-1.5 text-sm font-medium ${status === aba.status ? 'bg-primary text-primary-foreground' : 'bg-surface text-muted-foreground'}`}
            >
              {aba.rotulo}
            </button>
          ))}
        </div>
        <div className="flex items-center gap-2">
          {ativos.length > 1 ? (
            <select
              aria-label="Funil"
              className="rounded-md border border-border bg-background px-2 py-1.5 text-sm"
              value={funil.id}
              onChange={(e) => setFunilEscolhido(e.target.value)}
            >
              {ativos.map((f) => (
                <option key={f.id} value={f.id}>{f.nome}</option>
              ))}
            </select>
          ) : null}
          {status === 'ABERTO' ? (
            <label className="flex items-center gap-1.5 text-sm">
              <input type="checkbox" checked={soParados} onChange={(e) => setSoParados(e.target.checked)} /> Só parados
            </label>
          ) : null}
          {podeEscrever ? <Button onClick={() => setCriando((v) => !v)}>Novo negócio</Button> : null}
        </div>
      </div>

      {criando && podeEscrever ? <NovoNegocioForm funil={funil} onCriado={() => setCriando(false)} /> : null}

      {negocios.isPending ? <p className="text-muted-foreground">Carregando...</p> : null}
      {negocios.isError ? <Banner tom="perigo">Não foi possível carregar os negócios.</Banner> : null}
      {negocios.isSuccess && status === 'ABERTO' ? (
        <KanbanDeNegocios
          funil={funil}
          negocios={soParados ? negocios.data.filter((n) => n.parado) : negocios.data}
          podeEscrever={podeEscrever}
        />
      ) : null}
      {negocios.isSuccess && status !== 'ABERTO' ? <TabelaDeFechados negocios={negocios.data} status={status} /> : null}
    </div>
  );
}
