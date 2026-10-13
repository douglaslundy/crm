export interface Periodo {
  de: string;
  ate: string;
}

const dois = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) => `${d.getFullYear()}-${dois(d.getMonth() + 1)}-${dois(d.getDate())}`;

/** Datas de calendário no relógio do navegador; o backend interpreta no fuso do negócio. */
export function mesAtual(hoje: Date = new Date()): Periodo {
  return {
    de: iso(new Date(hoje.getFullYear(), hoje.getMonth(), 1)),
    ate: iso(new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0)),
  };
}

export function mesAnterior(hoje: Date = new Date()): Periodo {
  return {
    de: iso(new Date(hoje.getFullYear(), hoje.getMonth() - 1, 1)),
    ate: iso(new Date(hoje.getFullYear(), hoje.getMonth(), 0)),
  };
}

export function ultimosDias(n: number, hoje: Date = new Date()): Periodo {
  return { de: iso(new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate() - (n - 1))), ate: iso(hoje) };
}

/** `AAAA-MM` quando o período é exatamente um mês (a meta só vale nesse caso); senão `null`. */
export function mesDoPeriodo(p: Periodo): string | null {
  const [ano, mes, dia] = p.de.split('-').map(Number);
  if (dia !== 1) return null;
  const ultimo = iso(new Date(ano, mes, 0));
  return p.ate === ultimo ? `${ano}-${dois(mes)}` : null;
}
