'use client';

import Link from 'next/link';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ILIMITADO, RECURSOS, ROTULO_MODULO, ROTULO_RECURSO, type Plano } from '@/features/assinatura/types';
import { formatarCentavos } from '@/lib/formatos';
import { usePlanosPublicos } from '../hooks/usePlanosPublicos';

function CartaoDePlano({ plano }: { plano: Plano }) {
  const limites = RECURSOS.filter((r) => plano.limites[r] !== 0);

  return (
    <Card className="flex flex-col">
      <CardHeader>
        <CardTitle><h2>{plano.nome}</h2></CardTitle>
        {plano.descricao ? <p className="text-sm text-muted-foreground">{plano.descricao}</p> : null}
      </CardHeader>
      <CardContent className="flex flex-1 flex-col gap-4">
        <p className="text-3xl font-semibold">
          {formatarCentavos(plano.preco_mensal_centavos)}
          <span className="text-sm font-normal text-muted-foreground">/mês</span>
        </p>
        {plano.dias_teste > 0 ? <p className="text-sm font-medium text-success">{plano.dias_teste} dias grátis</p> : null}
        <ul className="flex flex-wrap gap-2" aria-label="Módulos">
          {plano.modulos.map((m) => (
            <li key={m} className="rounded-full bg-accent px-2 py-0.5 text-xs">{ROTULO_MODULO[m]}</li>
          ))}
        </ul>
        <ul className="space-y-1 text-sm text-muted-foreground" aria-label="Limites">
          {limites.map((r) => (
            <li key={r}>{ROTULO_RECURSO[r]}: {plano.limites[r] === ILIMITADO ? 'ilimitado' : plano.limites[r]}</li>
          ))}
        </ul>
        <Link href={`/cadastro?plano=${plano.id}`} className={buttonVariants({ className: 'mt-auto w-full' })}>
          Começar
        </Link>
      </CardContent>
    </Card>
  );
}

export function ListaDePlanos() {
  const { data, isPending, isError, refetch } = usePlanosPublicos();

  if (isPending) return <p className="text-muted-foreground">Carregando planos...</p>;
  if (isError) {
    return (
      <div className="space-y-2 text-center">
        <p>Não foi possível carregar os planos.</p>
        <Button onClick={() => void refetch()}>Tentar novamente</Button>
      </div>
    );
  }
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {data.map((plano) => <CartaoDePlano key={plano.id} plano={plano} />)}
    </div>
  );
}
