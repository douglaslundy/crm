'use client';

import Link from 'next/link';
import { useState } from 'react';
import { Banner } from '@/components/ui/banner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAssinatura } from '@/features/assinatura/hooks/useAssinatura';
import { ILIMITADO, RECURSOS, ROTULO_MODULO, ROTULO_RECURSO, type Plano } from '@/features/assinatura/types';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { usePlanosPublicos } from '@/features/cadastro/hooks/usePlanosPublicos';
import { formatarCentavos, formatarData } from '@/lib/formatos';
import { Checkout } from './Checkout';

const PAPEIS_QUE_ASSINAM = ['PROPRIETARIO', 'ADMIN'];

function CartaoDoPlano({ plano, selecionado, aoEscolher }: { plano: Plano; selecionado: boolean; aoEscolher: () => void }) {
  const limites = RECURSOS.filter((r) => plano.limites[r] !== 0);

  return (
    <Card className={selecionado ? 'border-primary' : undefined}>
      <CardHeader>
        <CardTitle><h2>{plano.nome}</h2></CardTitle>
        {plano.descricao ? <p className="text-sm text-muted-foreground">{plano.descricao}</p> : null}
      </CardHeader>
      <CardContent className="space-y-3">
        <p className="text-3xl font-semibold">
          {formatarCentavos(plano.preco_mensal_centavos)}
          <span className="text-sm font-normal text-muted-foreground">/mês</span>
        </p>
        {plano.preco_anual_centavos !== null ? <p className="text-sm text-muted-foreground">ou {formatarCentavos(plano.preco_anual_centavos)}/ano</p> : null}
        <ul className="flex flex-wrap gap-2" aria-label="Módulos">
          {plano.modulos.map((m) => <li key={m} className="rounded-full bg-accent px-2 py-0.5 text-xs">{ROTULO_MODULO[m]}</li>)}
        </ul>
        <ul className="space-y-1 text-sm text-muted-foreground" aria-label="Limites">
          {limites.map((r) => <li key={r}>{ROTULO_RECURSO[r]}: {plano.limites[r] === ILIMITADO ? 'ilimitado' : plano.limites[r]}</li>)}
        </ul>
        <Button type="button" aria-label={`Assinar ${plano.nome}`} variant={selecionado ? 'secondary' : 'default'} onClick={aoEscolher}>
          {selecionado ? 'Plano escolhido' : 'Assinar'}
        </Button>
      </CardContent>
    </Card>
  );
}

/** Página de planos e checkout do assinante. */
export function Assinar() {
  const { data: usuario } = useUsuario();
  const planos = usePlanosPublicos();
  const assinatura = useAssinatura();
  const [escolhido, setEscolhido] = useState<string | null>(null);

  if (usuario && !PAPEIS_QUE_ASSINAM.includes(usuario.papel)) {
    return <Banner tom="info">Só o proprietário ou um administrador da empresa gerencia a assinatura.</Banner>;
  }
  if (planos.isPending) return <p className="text-muted-foreground">Carregando planos...</p>;
  if (planos.isError) return <Banner tom="perigo">Não foi possível carregar os planos. Recarregue a página.</Banner>;

  const atual = assinatura.data?.assinatura ?? null;
  const emAtraso = assinatura.data?.situacao === 'INADIMPLENTE' || assinatura.data?.situacao === 'SUSPENSA';
  const planoAtual = assinatura.data?.plano ?? null;
  if (atual?.situacao === 'ATIVA' && emAtraso && planoAtual !== null) {
    return (
      <div className="space-y-4">
        <Banner tom="alerta">Há um pagamento em atraso na sua assinatura. Gere o boleto de regularização para voltar à situação normal.</Banner>
        <Card>
          <CardHeader><CardTitle>Regularizar: {planoAtual.nome}</CardTitle></CardHeader>
          <CardContent><Checkout key={planoAtual.id} plano={planoAtual} regularizar={{ ciclo: atual.ciclo }} /></CardContent>
        </Card>
        <Link href="/assinatura/faturas" className="text-sm underline">Ver faturas</Link>
      </div>
    );
  }
  if (atual?.situacao === 'ATIVA') {
    return (
      <div className="space-y-2">
        <Banner tom="sucesso">Sua assinatura está ativa{atual.paga_ate ? ` até ${formatarData(atual.paga_ate)}` : ''}.</Banner>
        <Link href="/assinatura/faturas" className="text-sm underline">Ver faturas ou cancelar</Link>
      </div>
    );
  }

  const plano = planos.data.find((p) => p.id === escolhido);

  return (
    <div className="space-y-6">
      {atual?.situacao === 'PENDENTE' ? (
        <Banner tom="info">Há um pagamento em aberto. <Link href="/assinatura/faturas" className="underline">Ver faturas</Link>. Ao assinar de novo, o pedido anterior sem pagamento é substituído.</Banner>
      ) : null}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {planos.data.map((p) => <CartaoDoPlano key={p.id} plano={p} selecionado={p.id === escolhido} aoEscolher={() => setEscolhido(p.id)} />)}
      </div>
      {plano ? (
        <Card>
          <CardHeader><CardTitle>Pagamento: {plano.nome}</CardTitle></CardHeader>
          <CardContent><Checkout key={plano.id} plano={plano} /></CardContent>
        </Card>
      ) : null}
    </div>
  );
}
