import { Pilula } from '@/components/ui/pilula';

export function SeloDeParado({ dias }: { dias: number }) {
  return (
    <Pilula tom="alerta" contorno>
      Parado há {dias} {dias === 1 ? 'dia' : 'dias'}
    </Pilula>
  );
}
