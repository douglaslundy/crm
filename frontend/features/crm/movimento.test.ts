import { describe, expect, it } from 'vitest';
import { aplicarMovimento, destinoDoArraste } from './movimento';
import type { Negocio } from './types';

function n(id: string, etapa: string, posicao: number): Negocio {
  return {
    id, titulo: id, cliente: null, funil_id: 'f', etapa_id: etapa, responsavel: null, status: 'ABERTO',
    valor_centavos: 0, previsao_fechamento: null, posicao_na_etapa: posicao, motivo_de_perda_id: null,
    observacao_da_perda: null, fechado_em: null, itens: [],
  };
}

describe('aplicarMovimento', () => {
  it('move para outra etapa e reindexa as duas colunas', () => {
    const lista = [n('a', 'e1', 0), n('b', 'e1', 1), n('c', 'e2', 0)];
    const r = aplicarMovimento(lista, 'a', 'e2', 0);

    expect(r.filter((x) => x.etapa_id === 'e1').map((x) => [x.id, x.posicao_na_etapa])).toEqual([['b', 0]]);
    expect(
      r.filter((x) => x.etapa_id === 'e2').sort((x, y) => x.posicao_na_etapa - y.posicao_na_etapa).map((x) => x.id),
    ).toEqual(['a', 'c']);
  });

  it('posição acima do tamanho vai para o fim', () => {
    const r = aplicarMovimento([n('a', 'e1', 0), n('c', 'e2', 0)], 'a', 'e2', 99);
    expect(r.find((x) => x.id === 'a')?.posicao_na_etapa).toBe(1);
  });

  it('reordena dentro da mesma etapa', () => {
    const r = aplicarMovimento([n('a', 'e1', 0), n('b', 'e1', 1)], 'b', 'e1', 0);
    expect(r.sort((x, y) => x.posicao_na_etapa - y.posicao_na_etapa).map((x) => x.id)).toEqual(['b', 'a']);
  });
});

describe('destinoDoArraste', () => {
  const lista = [n('a', 'e1', 0), n('b', 'e1', 1), n('c', 'e2', 0)];

  it('soltar na coluna vai para o fim dela', () => {
    expect(destinoDoArraste('etapa:e2', lista, 'a')).toEqual({ etapaId: 'e2', posicao: 1 });
  });

  it('soltar sobre um cartão entra na posição dele', () => {
    expect(destinoDoArraste('c', lista, 'a')).toEqual({ etapaId: 'e2', posicao: 0 });
  });

  it('soltar sobre si mesmo não move', () => {
    expect(destinoDoArraste('a', lista, 'a')).toBeNull();
  });

  describe('dentro da mesma coluna', () => {
    const coluna = [n('a', 'e1', 0), n('b', 'e1', 1), n('c', 'e1', 2)];

    it('arrastar para baixo sobre um cartão fica depois dele', () => {
      expect(destinoDoArraste('b', coluna, 'a')).toEqual({ etapaId: 'e1', posicao: 1 });
      expect(destinoDoArraste('c', coluna, 'a')).toEqual({ etapaId: 'e1', posicao: 2 });
    });

    it('arrastar para cima sobre um cartão fica antes dele', () => {
      expect(destinoDoArraste('a', coluna, 'c')).toEqual({ etapaId: 'e1', posicao: 0 });
      expect(destinoDoArraste('b', coluna, 'c')).toEqual({ etapaId: 'e1', posicao: 1 });
    });
  });
});
