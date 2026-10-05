'use client';

import { Bar, BarChart, Cell, ResponsiveContainer, Tooltip, XAxis } from 'recharts';
import { formatarCentavos } from '@/lib/formatos';
import { mesDeMaiorValor, rotuloDoMes } from '../helpers';
import type { FaturamentoDoMes } from '../types';

/** O desenho é visual; a tabela `sr-only` entrega os mesmos números a quem não enxerga cores ou barras. */
export function GraficoDeFaturamento({ itens }: { itens: FaturamentoDoMes[] }) {
  const maior = mesDeMaiorValor(itens);
  const dados = itens.map((i) => ({ rotulo: rotuloDoMes(i.mes), reais: i.valor_centavos / 100, destaque: i.mes === maior }));

  return (
    <div>
      <div aria-hidden className="h-[200px] w-full">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={dados} margin={{ top: 8, right: 0, bottom: 0, left: 0 }}>
            <XAxis dataKey="rotulo" tickLine={false} axisLine={false} tick={{ fontSize: 12, fill: 'var(--muted-foreground)' }} />
            <Tooltip
              cursor={{ fill: 'var(--hover)' }}
              formatter={(valor) => [formatarCentavos(Math.round(Number(valor) * 100)), 'Faturamento']}
              contentStyle={{ background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 8 }}
            />
            <Bar dataKey="reais" radius={[4, 4, 0, 0]}>
              {dados.map((d) => (
                <Cell key={d.rotulo} fill="var(--primary)" fillOpacity={d.destaque ? 1 : 0.3} />
              ))}
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </div>
      <table aria-label="Faturamento dos últimos 12 meses" className="sr-only">
        <thead>
          <tr><th scope="col">Mês</th><th scope="col">Valor emitido</th><th scope="col">Notas</th></tr>
        </thead>
        <tbody>
          {itens.map((i) => (
            <tr key={i.mes}>
              <th scope="row">{rotuloDoMes(i.mes)}</th>
              <td>{formatarCentavos(i.valor_centavos)}</td>
              <td>{i.quantidade}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
