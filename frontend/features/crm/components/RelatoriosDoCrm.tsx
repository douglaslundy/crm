'use client';

import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { SelectNativo } from '@/components/ui/campos-nativos';
import { Label } from '@/components/ui/label';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { QUERY_KEY_USUARIOS, usuariosApi } from '@/features/usuarios/api';
import { useRelatorio } from '../hooks/useRelatorio';
import { mesAtual, mesDoPeriodo, type Periodo } from '../periodos';
import { FiltroDePeriodo } from './FiltroDePeriodo';
import { MetasDoMes } from './MetasDoMes';
import { PerdasDoPeriodo } from './PerdasDoPeriodo';
import { TabelaDeVendedores } from './TabelaDeVendedores';
import { VisaoGeralDoRelatorio } from './VisaoGeralDoRelatorio';

type Aba = 'geral' | 'vendedores' | 'perdas' | 'metas';

const PAPEIS_QUE_CONFIGURAM = ['PROPRIETARIO', 'ADMIN'];

function BotaoDeAba({ ativa, aoClicar, children }: { ativa: boolean; aoClicar: () => void; children: string }) {
  return (
    <Button size="sm" variant={ativa ? 'default' : 'outline'} aria-pressed={ativa} onClick={aoClicar}>
      {children}
    </Button>
  );
}

export function RelatoriosDoCrm() {
  const { data: usuario } = useUsuario();
  const podeConfigurar = Boolean(usuario && PAPEIS_QUE_CONFIGURAM.includes(usuario.papel));
  const [periodo, setPeriodo] = useState<Periodo>(() => mesAtual());
  const [vendedorId, setVendedorId] = useState('');
  const [aba, setAba] = useState<Aba>('geral');
  const relatorio = useRelatorio(periodo, vendedorId || undefined);
  const usuarios = useQuery({
    queryKey: QUERY_KEY_USUARIOS,
    queryFn: async () => (await usuariosApi.listar()).data,
    enabled: podeConfigurar,
  });

  const dados = relatorio.data;
  const mes = mesDoPeriodo(periodo);

  return (
    <div className="space-y-4">
      <h1 className="font-display text-[28px] leading-tight font-extrabold">Relatórios do CRM</h1>

      <div className="flex flex-wrap items-end gap-3">
        <FiltroDePeriodo valor={periodo} aoMudar={setPeriodo} />
        {podeConfigurar ? (
          <div className="space-y-1">
            <Label htmlFor="filtro-vendedor">Filtrar por vendedor</Label>
            <SelectNativo id="filtro-vendedor" value={vendedorId} onChange={(e) => setVendedorId(e.target.value)}>
              <option value="">Todos</option>
              {(usuarios.data ?? []).filter((u) => u.ativo).map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}
            </SelectNativo>
          </div>
        ) : null}
      </div>

      {relatorio.isError ? <Banner tom="perigo">Não foi possível carregar o relatório.</Banner> : null}
      {relatorio.isPending ? <p className="text-muted-foreground">Carregando...</p> : null}

      {dados ? (
        <>
          <div className="flex flex-wrap gap-2" role="group" aria-label="Seções do relatório">
            <BotaoDeAba ativa={aba === 'geral'} aoClicar={() => setAba('geral')}>Visão geral</BotaoDeAba>
            {dados.vendedores !== null ? (
              <BotaoDeAba ativa={aba === 'vendedores'} aoClicar={() => setAba('vendedores')}>Vendedores</BotaoDeAba>
            ) : null}
            <BotaoDeAba ativa={aba === 'perdas'} aoClicar={() => setAba('perdas')}>Perdas</BotaoDeAba>
            {podeConfigurar ? <BotaoDeAba ativa={aba === 'metas'} aoClicar={() => setAba('metas')}>Metas</BotaoDeAba> : null}
          </div>

          {aba === 'geral' ? <VisaoGeralDoRelatorio relatorio={dados} /> : null}
          {aba === 'vendedores' && dados.vendedores !== null ? (
            <TabelaDeVendedores vendedores={dados.vendedores} mesInteiro={mes !== null} />
          ) : null}
          {aba === 'perdas' ? <PerdasDoPeriodo motivos={dados.motivos_de_perda} /> : null}
          {aba === 'metas' && podeConfigurar ? <MetasDoMes mes={mes} /> : null}
        </>
      ) : null}
    </div>
  );
}
