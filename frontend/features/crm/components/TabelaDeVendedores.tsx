'use client';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatarCentavos } from '@/lib/formatos';
import type { ProgressoDeMeta, VendedorDoRelatorio } from '../types';

const dinheiroOuQuantidade = (unidade: ProgressoDeMeta['unidade'], n: number) => (unidade === 'VALOR' ? formatarCentavos(n) : `${n}`);

/** Barra própria: as cores de BarraDeProgresso significam consumo de plano, não meta batida. */
function ProgressoDaMeta({ nome, meta }: { nome: string; meta: ProgressoDeMeta }) {
  return (
    <div className="space-y-1">
      <div
        role="progressbar"
        aria-label={`Meta de ${nome}`}
        aria-valuenow={meta.percentual}
        aria-valuemin={0}
        aria-valuemax={100}
        className="h-1.5 w-full overflow-hidden rounded-[3px] bg-border"
      >
        <div className="h-full bg-primary" style={{ width: `${Math.min(meta.percentual, 100)}%` }} />
      </div>
      <p className="text-xs text-muted-foreground">
        {dinheiroOuQuantidade(meta.unidade, meta.realizado)} de {dinheiroOuQuantidade(meta.unidade, meta.alvo)} ({meta.percentual}%)
      </p>
    </div>
  );
}

function SemMeta({ mesInteiro }: { mesInteiro: boolean }) {
  return (
    <p className="text-xs text-muted-foreground">
      {mesInteiro ? 'Sem meta no mês.' : 'A meta só aparece quando o período é um mês inteiro.'}
    </p>
  );
}

export function TabelaDeVendedores({ vendedores, mesInteiro }: { vendedores: VendedorDoRelatorio[]; mesInteiro: boolean }) {
  if (vendedores.length === 0) {
    return <p className="text-sm text-muted-foreground">Nenhum vendedor com negócios ou meta no período.</p>;
  }

  return (
    <Card>
      <CardHeader><CardTitle>Desempenho por vendedor</CardTitle></CardHeader>
      <CardContent>
        <table className="hidden w-full text-sm md:table">
          <thead>
            <tr className="text-left text-xs text-muted-foreground uppercase">
              <th scope="col" className="py-2">Vendedor</th>
              <th scope="col">Ganhos</th>
              <th scope="col">Perdidos</th>
              <th scope="col">Abertos</th>
              <th scope="col" className="w-1/4">Meta</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {vendedores.map((v) => (
              <tr key={v.usuario_id}>
                <th scope="row" className="py-2 text-left font-medium">{v.nome}</th>
                <td>{v.ganhos.quantidade} · {formatarCentavos(v.ganhos.valor_centavos)}</td>
                <td>{v.perdidos.quantidade} · {formatarCentavos(v.perdidos.valor_centavos)}</td>
                <td>{v.abertos.quantidade} · {formatarCentavos(v.abertos.valor_centavos)}</td>
                <td>{v.meta ? <ProgressoDaMeta nome={v.nome} meta={v.meta} /> : <SemMeta mesInteiro={mesInteiro} />}</td>
              </tr>
            ))}
          </tbody>
        </table>

        <ul className="space-y-3 md:hidden">
          {vendedores.map((v) => (
            <li key={v.usuario_id} className="space-y-2 rounded-lg border border-border p-3 text-sm">
              <p className="font-medium">{v.nome}</p>
              <p className="text-muted-foreground">
                Ganhos {v.ganhos.quantidade} · {formatarCentavos(v.ganhos.valor_centavos)}; perdidos {v.perdidos.quantidade}; abertos {v.abertos.quantidade}
              </p>
              {v.meta ? <ProgressoDaMeta nome={v.nome} meta={v.meta} /> : <SemMeta mesInteiro={mesInteiro} />}
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
