import { ApiError } from './api';

/** Sessão caída (401) em qualquer query ou mutation leva ao login. `local` é injetável para teste. */
export function irParaLoginSeNaoAutenticado(
  erro: unknown,
  local: Pick<Location, 'pathname' | 'assign'> = window.location,
): void {
  if (erro instanceof ApiError && erro.status === 401 && !local.pathname.startsWith('/login')) {
    // Fora do React não há router; o reload completo também descarta o estado da sessão expirada.
    local.assign('/login');
  }
}
