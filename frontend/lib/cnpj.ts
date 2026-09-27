/**
 * CNPJ numérico ou alfanumérico (IN RFB 2.229/2024). Mesma regra do backend
 * (App\Modules\Shared\Domain\Cnpj): valor do caractere = código ASCII − 48.
 */
const PESOS_DV1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
const PESOS_DV2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

export function normalizarCnpj(valor: string): string {
  return valor.toUpperCase().replace(/[^0-9A-Z]/g, '');
}

function digito(base: string, pesos: number[]): number {
  const soma = pesos.reduce((total, peso, i) => total + (base.charCodeAt(i) - 48) * peso, 0);
  const resto = soma % 11;
  return resto < 2 ? 0 : 11 - resto;
}

export function validarCnpj(valor: string): boolean {
  const cnpj = normalizarCnpj(valor);
  if (!/^[0-9A-Z]{12}[0-9]{2}$/.test(cnpj) || /^(.)\1{13}$/.test(cnpj)) return false;
  const base = cnpj.slice(0, 12);
  const dv1 = digito(base, PESOS_DV1);
  const dv2 = digito(base + dv1, PESOS_DV2);
  return cnpj.slice(12) === `${dv1}${dv2}`;
}

export function mascararCnpj(valor: string): string {
  const c = normalizarCnpj(valor).slice(0, 14);
  const partes: [string, string][] = [['', c.slice(0, 2)], ['.', c.slice(2, 5)], ['.', c.slice(5, 8)], ['/', c.slice(8, 12)], ['-', c.slice(12, 14)]];
  return partes.filter(([, trecho]) => trecho !== '').map(([sep, trecho], i) => (i === 0 ? trecho : sep + trecho)).join('');
}
