import { cn } from '@/lib/utils';
import { ILIMITADO, ROTULO_RECURSO, type ItemConsumo } from '../types';

export function ConsumoDoPlano({ itens }: { itens: ItemConsumo[] }) {
  return (
    <ul className="space-y-3">
      {itens.map(({ recurso, uso, limite }) => {
        const rotulo = ROTULO_RECURSO[recurso];
        if (limite === ILIMITADO) {
          return (
            <li key={recurso} className="flex justify-between text-sm">
              <span>{rotulo}</span>
              <span className="text-muted-foreground">{uso} · ilimitado</span>
            </li>
          );
        }
        const pct = limite === 0 ? 100 : Math.min(100, Math.round((uso / limite) * 100));
        return (
          <li key={recurso} className="space-y-1">
            <div className="flex justify-between text-sm">
              <span>{rotulo}</span>
              <span className="text-muted-foreground">{uso} de {limite}</span>
            </div>
            <div role="progressbar" aria-label={rotulo} aria-valuemin={0} aria-valuemax={limite} aria-valuenow={uso} className="h-2 rounded-full bg-muted">
              <div
                className={cn('h-2 rounded-full', pct >= 100 ? 'bg-danger' : pct >= 80 ? 'bg-warning' : 'bg-primary')}
                style={{ width: `${pct}%` }}
              />
            </div>
          </li>
        );
      })}
    </ul>
  );
}
