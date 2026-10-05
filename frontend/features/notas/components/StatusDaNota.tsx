import { Pilula, type TomDaPilula } from '@/components/ui/pilula';
import { ROTULO_STATUS, type StatusNota } from '../types';

const TOM: Record<StatusNota, { tom: TomDaPilula; contorno?: boolean }> = {
  RASCUNHO: { tom: 'neutro' },
  PROCESSANDO: { tom: 'alerta' },
  AUTORIZADA: { tom: 'sucesso' },
  REJEITADA: { tom: 'perigo' },
  ERRO: { tom: 'perigo' },
  CANCELADA: { tom: 'perigo', contorno: true },
  CANCELANDO: { tom: 'alerta', contorno: true },
};

/** Texto sempre presente: a cor sozinha não basta (acessibilidade). O ambiente fica visível ao lado. */
export function StatusDaNota({ status, ambiente }: { status: StatusNota; ambiente?: 'HOMOLOGACAO' | 'PRODUCAO' | null }) {
  const { tom, contorno } = TOM[status];
  return (
    <span className="inline-flex flex-wrap items-center gap-1.5">
      <Pilula tom={tom} contorno={contorno}>{ROTULO_STATUS[status]}</Pilula>
      {ambiente ? (
        <Pilula tom={ambiente === 'PRODUCAO' ? 'perigo' : 'info'} contorno>
          {ambiente === 'PRODUCAO' ? 'Produção' : 'Homologação'}
        </Pilula>
      ) : null}
    </span>
  );
}
