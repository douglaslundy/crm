const moeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

export function formatarCentavos(centavos: number): string {
  return moeda.format(centavos / 100);
}

/** 'AAAA-MM-DD' → 'DD/MM/AAAA', sem passar por Date (que deslocaria o fuso). */
export function formatarData(iso: string): string {
  const [ano, mes, dia] = iso.slice(0, 10).split('-');
  return `${dia}/${mes}/${ano}`;
}

/** '99,90' → 9990. Aritmética inteira: nada de float com dinheiro. Entrada já validada por regex. */
export function reaisParaCentavos(texto: string): number {
  const [inteiro, fracao = ''] = texto.trim().split(',');
  return Number(inteiro) * 100 + Number(fracao.padEnd(2, '0').slice(0, 2));
}

export function centavosParaReais(centavos: number): string {
  return `${Math.trunc(centavos / 100)},${String(centavos % 100).padStart(2, '0')}`;
}
