'use client';

import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { useAtividadesDoNegocio } from '../hooks/useAtividades';
import type { Negocio } from '../types';
import { FormularioDeAtividade } from './FormularioDeAtividade';
import { ItemDeAtividade } from './ItemDeAtividade';

export function AtividadesDoNegocio({ negocio, podeEscrever }: { negocio: Negocio; podeEscrever: boolean }) {
  const atividades = useAtividadesDoNegocio(negocio.id);
  const [criando, setCriando] = useState(false);
  const lista = atividades.data ?? [];
  const podeCriar = podeEscrever && negocio.status === 'ABERTO';

  return (
    <div className="space-y-3">
      {podeCriar ? <Button size="sm" onClick={() => setCriando((v) => !v)}>Nova atividade</Button> : null}
      {criando ? <FormularioDeAtividade negocioId={negocio.id} aoCriar={() => setCriando(false)} /> : null}
      {atividades.isSuccess && lista.length === 0 ? (
        <p className="text-sm text-muted-foreground">Nenhuma atividade neste negócio.</p>
      ) : null}
      <ul className="space-y-2">
        {lista.map((a) => <ItemDeAtividade key={a.id} atividade={a} podeEscrever={podeEscrever} />)}
      </ul>
    </div>
  );
}
