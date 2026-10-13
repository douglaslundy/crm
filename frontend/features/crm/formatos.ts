/** Fração de 0 a 1 vira "66,7 %"; `null` (sem denominador) vira traço, nunca 0%. */
export function percentual(taxa: number | null): string {
  return taxa === null ? '—' : `${(taxa * 100).toFixed(1).replace('.', ',')} %`;
}
