/**
 * Divide o desconto geral entre a nota de produtos e a de serviços, em proporção aos subtotais (centavos inteiros).
 * Mesma aritmética do backend (`RateioDeDesconto::ratear`): o resíduo vai para o último que ainda comporta.
 * É só a prévia da tela; a conta que vale é a do servidor.
 */
export function ratearDesconto(desconto: number, totais: [number, number]): [number, number] {
  const subtotal = totais[0] + totais[1];
  if (desconto < 0 || desconto > subtotal) throw new Error('desconto');
  if (desconto === 0 || subtotal === 0) return [0, 0];

  const partes: [number, number] = [Math.floor((desconto * totais[0]) / subtotal), Math.floor((desconto * totais[1]) / subtotal)];
  let residuo = desconto - partes[0] - partes[1];
  for (let i = 1; i >= 0 && residuo > 0; i--) {
    const acrescimo = Math.min(residuo, totais[i] - partes[i]);
    partes[i] += acrescimo;
    residuo -= acrescimo;
  }
  return partes;
}
