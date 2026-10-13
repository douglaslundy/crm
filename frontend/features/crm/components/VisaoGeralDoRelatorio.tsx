'use client';

import { Bar, BarChart, ResponsiveContainer, Tooltip, XAxis } from 'recharts';
import { StatCard } from '@/components/ui/stat-card';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatarCentavos } from '@/lib/formatos';
import { percentual } from '../formatos';
import type { RelatorioComercial } from '../types';

export function VisaoGeralDoRelatorio({ relatorio: r }: { relatorio: RelatorioComercial }) {
  const etapas = r.funil?.etapas ?? [];
  const dados = etapas.map((e) => ({ rotulo: e.nome, reais: e.valor_centavos / 100 }));

  return (
    <div className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <StatCard
          titulo="Ganhos"
          valor={formatarCentavos(r.resumo.ganhos.valor_centavos)}
          legenda={`${r.resumo.ganhos.quantidade} negócios ganhos`}
          tom="sucesso"
        />
        <StatCard
          titulo="Perdidos"
          valor={formatarCentavos(r.resumo.perdidos.valor_centavos)}
          legenda={`${r.resumo.perdidos.quantidade} negócios perdidos`}
          tom="perigo"
        />
        <StatCard
          titulo="Em aberto"
          valor={formatarCentavos(r.resumo.abertos.valor_centavos)}
          legenda={`${r.resumo.abertos.quantidade} negócios abertos`}
        />
        <StatCard titulo="Conversão" valor={percentual(r.resumo.taxa_de_conversao)} legenda="ganhos entre os negócios fechados" />
        <StatCard
          titulo="Previsão ponderada"
          valor={formatarCentavos(r.previsao.no_periodo.ponderada_centavos)}
          legenda={`${r.previsao.no_periodo.quantidade} com previsão no período; ${r.previsao.sem_previsao.quantidade} sem data`}
          tom="info"
        />
        <StatCard
          titulo="Propostas aceitas"
          valor={formatarCentavos(r.propostas.valor_aceito_centavos)}
          legenda={`${r.propostas.aceitas} aceitas de ${r.propostas.enviadas} enviadas · aceite ${percentual(r.propostas.taxa_de_aceite)}`}
        />
        {r.faturado_centavos !== null ? (
          <StatCard
            titulo="Faturado"
            valor={formatarCentavos(r.faturado_centavos)}
            legenda="notas autorizadas das propostas aceitas"
            tom="sucesso"
          />
        ) : null}
      </div>

      <Card>
        <CardHeader><CardTitle>Funil{r.funil ? `: ${r.funil.nome}` : ''}</CardTitle></CardHeader>
        <CardContent className="space-y-3">
          {r.funil === null ? (
            <p className="text-sm text-muted-foreground">Nenhum funil configurado.</p>
          ) : (
            <>
              <div aria-hidden className="h-[220px] w-full">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={dados} margin={{ top: 8, right: 0, bottom: 0, left: 0 }}>
                    <XAxis dataKey="rotulo" tickLine={false} axisLine={false} tick={{ fontSize: 12, fill: 'var(--muted-foreground)' }} />
                    <Tooltip
                      cursor={{ fill: 'var(--hover)' }}
                      formatter={(valor) => [formatarCentavos(Math.round(Number(valor) * 100)), 'Valor em aberto']}
                      contentStyle={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 8 }}
                    />
                    <Bar dataKey="reais" fill="var(--primary)" radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
              <table aria-label="Negócios abertos por etapa" className="sr-only">
                <thead>
                  <tr><th scope="col">Etapa</th><th scope="col">Negócios</th><th scope="col">Valor</th></tr>
                </thead>
                <tbody>
                  {etapas.map((e) => (
                    <tr key={e.id}>
                      <th scope="row">{e.nome}</th>
                      <td>{e.quantidade}</td>
                      <td>{formatarCentavos(e.valor_centavos)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              <ul className="grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                {etapas.map((e) => (
                  <li key={e.id} className="flex items-baseline justify-between gap-2 rounded-lg border border-border px-3 py-2">
                    <span>{e.nome}</span>
                    <span className="text-muted-foreground">{e.quantidade} · {formatarCentavos(e.valor_centavos)}</span>
                  </li>
                ))}
              </ul>
              <p className="text-xs text-muted-foreground">
                O funil mostra a situação de agora; o período vale para ganhos, perdas, previsão e propostas.
              </p>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
