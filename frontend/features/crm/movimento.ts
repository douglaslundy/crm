import type { Negocio } from './types';

export const PREFIXO_COLUNA = 'etapa:';

const porPosicao = (a: Negocio, b: Negocio) => a.posicao_na_etapa - b.posicao_na_etapa;

function reindexar(lista: Negocio[]): Negocio[] {
  return lista.map((n, i) => ({ ...n, posicao_na_etapa: i }));
}

/** Espelha a Action `MoverNegocio` do backend para a atualização otimista. */
export function aplicarMovimento(negocios: Negocio[], id: string, etapaId: string, posicao: number): Negocio[] {
  const movido = negocios.find((n) => n.id === id);
  if (!movido) return negocios;

  const origemId = movido.etapa_id;
  const destino = negocios.filter((n) => n.etapa_id === etapaId && n.id !== id).sort(porPosicao);
  destino.splice(Math.max(0, Math.min(posicao, destino.length)), 0, { ...movido, etapa_id: etapaId });

  const restantes = negocios.filter((n) => n.id !== id && n.etapa_id !== etapaId);
  const origem = origemId === etapaId ? [] : reindexar(restantes.filter((n) => n.etapa_id === origemId).sort(porPosicao));
  const outros = origemId === etapaId ? restantes : restantes.filter((n) => n.etapa_id !== origemId);

  return [...outros, ...origem, ...reindexar(destino)];
}

export interface Alvo {
  etapaId: string;
  posicao: number;
}

/** Converte o alvo de um arraste (coluna `etapa:<id>` ou cartão) em etapa e posição. */
export function destinoDoArraste(overId: string, negocios: Negocio[], movidoId: string): Alvo | null {
  if (overId.startsWith(PREFIXO_COLUNA)) {
    const etapaId = overId.slice(PREFIXO_COLUNA.length);
    return { etapaId, posicao: negocios.filter((n) => n.etapa_id === etapaId && n.id !== movidoId).length };
  }

  const alvo = negocios.find((n) => n.id === overId);
  if (!alvo || alvo.id === movidoId) return null;

  const daEtapa = negocios.filter((n) => n.etapa_id === alvo.etapa_id && n.id !== movidoId).sort(porPosicao);

  return { etapaId: alvo.etapa_id, posicao: daEtapa.findIndex((n) => n.id === alvo.id) };
}
