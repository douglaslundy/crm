'use client';

import { useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import { buttonVariants } from '@/components/ui/button';
import { formatarCentavos } from '@/lib/formatos';
import { adminApi } from '../api';
import { Banner } from '@/components/ui/banner';
import { EstadoVazio } from '@/components/ui/estado-vazio';

export function ListaDePlanosAdmin() {
  const { data, isPending, isError } = useQuery({
    queryKey: ['admin', 'planos'],
    queryFn: async () => (await adminApi.planos()).data,
  });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-2">
        <h1 className="font-display text-[28px] leading-tight font-extrabold">Planos</h1>
        <Link href="/admin/planos/novo" className={buttonVariants()}>Novo plano</Link>
      </div>
      {isPending ? <p className="text-muted-foreground">Carregando...</p> : null}
      {isError ? <Banner tom="perigo">Não foi possível carregar os planos.</Banner> : null}
      {data && data.length === 0 ? <EstadoVazio titulo="Nenhum plano cadastrado">Use &quot;Novo plano&quot; para criar o primeiro.</EstadoVazio> : null}
      {data && data.length > 0 ? (
        <ul className="divide-y rounded-lg border">
          {data.map((plano) => (
            <li key={plano.id} className="flex flex-wrap items-center justify-between gap-2 p-3">
              <div className="min-w-0">
                <p className="font-medium">{plano.nome}</p>
                <p className="text-sm text-muted-foreground">
                  {formatarCentavos(plano.preco_mensal_centavos)}/mês · {plano.empresas ?? 0} empresa(s)
                  {plano.ativo ? '' : ' · desativado'}{plano.visivel ? '' : ' · oculto'}
                </p>
              </div>
              <Link href={`/admin/planos/${plano.id}`} className={buttonVariants({ variant: 'outline', size: 'sm' })}>
                Editar
              </Link>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
