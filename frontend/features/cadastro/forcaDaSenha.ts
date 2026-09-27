export function forcaDaSenha(senha: string): { nivel: 0 | 1 | 2 | 3; rotulo: '' | 'Fraca' | 'Média' | 'Forte' } {
  if (senha === '') return { nivel: 0, rotulo: '' };
  const pontos = [
    senha.length >= 8,
    /[A-Z]/.test(senha) && /[a-z]/.test(senha),
    /\d/.test(senha),
    senha.length >= 12 || /[^A-Za-z0-9]/.test(senha),
  ].filter(Boolean).length;
  if (pontos <= 2) return { nivel: 1, rotulo: 'Fraca' };
  if (pontos === 3) return { nivel: 2, rotulo: 'Média' };
  return { nivel: 3, rotulo: 'Forte' };
}
