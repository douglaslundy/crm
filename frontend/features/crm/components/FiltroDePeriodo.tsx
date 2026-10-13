'use client';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { mesAnterior, mesAtual, ultimosDias, type Periodo } from '../periodos';

interface Props {
  valor: Periodo;
  aoMudar: (p: Periodo) => void;
}

export function FiltroDePeriodo({ valor, aoMudar }: Props) {
  return (
    <div className="flex flex-wrap items-end gap-2">
      <Button size="sm" variant="outline" onClick={() => aoMudar(mesAtual())}>Este mês</Button>
      <Button size="sm" variant="outline" onClick={() => aoMudar(mesAnterior())}>Mês anterior</Button>
      <Button size="sm" variant="outline" onClick={() => aoMudar(ultimosDias(90))}>Últimos 90 dias</Button>
      <div className="space-y-1">
        <Label htmlFor="periodo-de">De</Label>
        <Input id="periodo-de" type="date" value={valor.de} onChange={(e) => e.target.value && aoMudar({ ...valor, de: e.target.value })} />
      </div>
      <div className="space-y-1">
        <Label htmlFor="periodo-ate">Até</Label>
        <Input id="periodo-ate" type="date" value={valor.ate} onChange={(e) => e.target.value && aoMudar({ ...valor, ate: e.target.value })} />
      </div>
    </div>
  );
}
