import type { SituacaoAssinatura } from '@/features/auth/types';
import { formatarData } from '@/lib/formatos';
import { cn } from '@/lib/utils';

interface Props {
  situacao: SituacaoAssinatura;
  testeTerminaEm: string | null;
}

export function FaixaSituacao({ situacao, testeTerminaEm }: Props) {
  const faixa = (() => {
    switch (situacao) {
      case 'TESTE':
        return { tom: 'bg-info/10 text-info', texto: testeTerminaEm ? `Teste grátis até ${formatarData(testeTerminaEm)}.` : 'Teste grátis.' };
      case 'INADIMPLENTE':
        return { tom: 'bg-warning/10 text-warning', texto: 'Há um pagamento pendente na sua assinatura.' };
      case 'SUSPENSA':
        return { tom: 'bg-danger/10 text-danger', texto: 'Conta suspensa. Você ainda pode consultar e baixar seus dados.' };
      case 'CANCELADA':
        return { tom: 'bg-danger/10 text-danger', texto: 'Conta cancelada. Você ainda pode consultar e baixar seus dados.' };
      default:
        return null;
    }
  })();

  if (!faixa) return null;
  return <p role="status" className={cn('rounded-md px-3 py-2 text-sm', faixa.tom)}>{faixa.texto}</p>;
}
