import { describe, expect, it } from 'vitest';
import { tempoRestante } from './contingencia';

describe('tempoRestante', () => {
  const agora = new Date('2026-10-05T15:30:00Z');

  it('mostra horas e minutos até o limite', () => {
    expect(tempoRestante('2026-10-06T10:00:00Z', agora)).toEqual({ vencido: false, texto: '18 h 30 min' });
  });

  it('mostra só minutos quando falta menos de uma hora', () => {
    expect(tempoRestante('2026-10-05T16:15:00Z', agora)).toEqual({ vencido: false, texto: '45 min' });
  });

  it('marca como vencido e diz há quanto tempo', () => {
    expect(tempoRestante('2026-10-05T14:00:00Z', agora)).toEqual({ vencido: true, texto: '1 h 30 min' });
  });

  it('o instante exato do limite ainda não é vencido', () => {
    expect(tempoRestante('2026-10-05T15:30:00Z', agora)).toEqual({ vencido: false, texto: '0 min' });
  });
});
