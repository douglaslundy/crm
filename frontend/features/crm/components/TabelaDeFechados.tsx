import Link from 'next/link';
import { formatarCentavos, formatarData } from '@/lib/formatos';
import type { Negocio } from '../types';

export function TabelaDeFechados({ negocios, status }: { negocios: Negocio[]; status: 'GANHO' | 'PERDIDO' }) {
  if (negocios.length === 0) {
    return <p className="text-muted-foreground">Nenhum negócio {status === 'GANHO' ? 'ganho' : 'perdido'}.</p>;
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead className="text-xs text-muted-foreground">
          <tr>
            <th className="py-2 pr-4 font-medium">Negócio</th>
            <th className="py-2 pr-4 font-medium">Cliente</th>
            <th className="py-2 pr-4 font-medium">Valor</th>
            <th className="py-2 font-medium">Fechado em</th>
          </tr>
        </thead>
        <tbody className="divide-y">
          {negocios.map((n) => (
            <tr key={n.id}>
              <td className="py-2 pr-4">
                <Link href={`/crm/negocios/${n.id}`} className="font-semibold hover:underline">{n.titulo}</Link>
              </td>
              <td className="py-2 pr-4">{n.cliente?.nome ?? '—'}</td>
              <td className="py-2 pr-4">{formatarCentavos(n.valor_centavos)}</td>
              <td className="py-2">{n.fechado_em ? formatarData(n.fechado_em) : '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
