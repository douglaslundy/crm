import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const css = readFileSync(join(__dirname, 'globals.css'), 'utf8');

function bloco(abertura: string): Record<string, string> {
  const inicio = css.indexOf(`\n${abertura} {`);
  if (inicio < 0) throw new Error(`bloco ${abertura} ausente em globals.css`);
  const fim = css.indexOf('\n}', inicio);
  const corpo = css.slice(inicio, fim);
  const vars: Record<string, string> = {};
  for (const m of corpo.matchAll(/--([\w-]+):\s*(#[0-9a-fA-F]{6})\b/g)) vars[m[1]] = m[2].toLowerCase();
  return vars;
}

function luminancia(hex: string): number {
  const canais = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255);
  const [r, g, b] = canais.map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contraste(a: string, b: string): number {
  const [x, y] = [luminancia(a), luminancia(b)].sort((p, q) => q - p);
  return (x + 0.05) / (y + 0.05);
}

type Par = [rotulo: string, frente: string, fundo: string, minimo: number];

function pares(t: Record<string, string>): Par[] {
  const v = (nome: string) => {
    const valor = nome === 'branco' ? '#ffffff' : t[nome];
    if (!valor) throw new Error(`token --${nome} ausente`);
    return valor;
  };
  const p = (rotulo: string, frente: string, fundo: string, minimo: number): Par => [rotulo, v(frente), v(fundo), minimo];
  return [
    p('texto/cartão', 'foreground', 'card', 4.5),
    p('texto/fundo', 'foreground', 'background', 4.5),
    p('secundário/cartão', 'muted-foreground', 'card', 4.5),
    p('secundário/fundo', 'muted-foreground', 'background', 4.5),
    p('secundário/menu', 'muted-foreground', 'surface', 4.5),
    p('secundário/hover', 'muted-foreground', 'hover', 4.5),
    p('texto sobre marca', 'primary-foreground', 'primary', 4.5),
    p('marca como texto/cartão', 'primary-text', 'card', 4.5),
    p('marca como texto/fundo', 'primary-text', 'background', 4.5),
    p('erro/cartão', 'danger-text', 'card', 4.5),
    p('sucesso/cartão', 'success-text', 'card', 4.5),
    p('info/cartão', 'info-text', 'card', 4.5),
    p('branco/danger-strong', 'branco', 'danger-strong', 4.5),
    p('branco/success-strong', 'branco', 'success-strong', 4.5),
    p('branco/info-strong', 'branco', 'info-strong', 4.5),
    p('branco/muted-strong', 'branco', 'muted-strong', 4.5),
    p('borda de campo/cartão', 'input', 'card', 3),
    p('borda de campo/fundo', 'input', 'background', 3),
    p('foco/cartão', 'ring', 'card', 3),
    p('foco/fundo', 'ring', 'background', 3),
  ];
}

describe.each([
  ['claro', ':root'],
  ['escuro', '.dark'],
])('contraste dos tokens (%s)', (_tema, abertura) => {
  const tokens = bloco(abertura);
  it.each(pares(tokens))('%s ≥ mínimo', (rotulo, frente, fundo, minimo) => {
    expect(contraste(frente, fundo), `${rotulo}: ${frente} sobre ${fundo}`).toBeGreaterThanOrEqual(minimo);
  });
});
