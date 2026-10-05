import type { FaturamentoDoMes } from './types';

export type FaixaDoCertificado = 'ok' | 'alerta' | 'vencido' | 'sem-validade';

/** Sem validade conhecida nunca é "ok": o servidor manda nulo, não zero. */
export function faixaDoCertificado(diasRestantes: number | null): FaixaDoCertificado {
  if (diasRestantes === null) return 'sem-validade';
  if (diasRestantes < 0) return 'vencido';
  if (diasRestantes <= 30) return 'alerta';
  return 'ok';
}

const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

/** 'AAAA-MM' → 'out/2026', sem passar por Date (que deslocaria o fuso). */
export function rotuloDoMes(mes: string): string {
  const [ano, numero] = mes.split('-');
  return `${MESES[Number(numero) - 1]}/${ano}`;
}

export function mesDeMaiorValor(itens: FaturamentoDoMes[]): string | null {
  let melhor: FaturamentoDoMes | null = null;
  for (const item of itens) {
    if (item.valor_centavos > 0 && (melhor === null || item.valor_centavos > melhor.valor_centavos)) melhor = item;
  }
  return melhor?.mes ?? null;
}
