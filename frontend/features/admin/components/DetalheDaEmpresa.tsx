'use client';

import { useQuery } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ConsumoDoPlano } from '@/features/assinatura/components/ConsumoDoPlano';
import { useUsuario } from '@/features/auth/hooks/useUsuario';
import { mascararCnpj } from '@/lib/cnpj';
import { formatarCentavos, formatarData } from '@/lib/formatos';
import { adminApi } from '../api';
import { BadgeSituacao } from './BadgeSituacao';
import { MudarSituacaoForm } from './MudarSituacaoForm';
import { TrocarPlanoForm } from './TrocarPlanoForm';

export function DetalheDaEmpresa({ id }: { id: string }) {
  const { data: usuario } = useUsuario();
  const { data: empresa, isPending, isError } = useQuery({
    queryKey: ['admin', 'empresas', id],
    queryFn: async () => (await adminApi.empresa(id)).data,
  });

  if (isPending) return <p className="text-muted-foreground">Carregando...</p>;
  if (isError) return <p role="alert">Não foi possível carregar a empresa.</p>;

  const podeAgir = usuario?.papel === 'SUPERADMIN';

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <h1 className="text-xl font-semibold">{empresa.nome_fantasia ?? empresa.razao_social}</h1>
        <BadgeSituacao situacao={empresa.situacao} />
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader><CardTitle>Dados</CardTitle></CardHeader>
          <CardContent>
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
              <dt className="text-muted-foreground">Razão social</dt><dd>{empresa.razao_social}</dd>
              <dt className="text-muted-foreground">CNPJ</dt><dd>{mascararCnpj(empresa.cnpj)}</dd>
              <dt className="text-muted-foreground">Plano</dt>
              <dd>{empresa.plano ? `${empresa.plano.nome} (${formatarCentavos(empresa.plano.preco_mensal_centavos)}/mês)` : 'Sem plano'}</dd>
              {empresa.teste_termina_em ? (<><dt className="text-muted-foreground">Teste até</dt><dd>{formatarData(empresa.teste_termina_em)}</dd></>) : null}
              <dt className="text-muted-foreground">Criada em</dt><dd>{formatarData(empresa.criada_em)}</dd>
            </dl>
          </CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle>Consumo</CardTitle></CardHeader>
          <CardContent><ConsumoDoPlano itens={empresa.consumo ?? []} /></CardContent>
        </Card>
        {podeAgir ? (
          <>
            <MudarSituacaoForm empresa={empresa} />
            <TrocarPlanoForm empresa={empresa} />
          </>
        ) : (
          <p className="text-sm text-muted-foreground">Seu perfil de suporte só permite consultar.</p>
        )}
      </div>
    </div>
  );
}
