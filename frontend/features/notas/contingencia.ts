/** Tempo até o limite de transmissão de uma NFC-e em contingência (ou há quanto tempo ele passou). */
export function tempoRestante(limite: string, agora: Date = new Date()): { vencido: boolean; texto: string } {
  const diferencaEmMinutos = Math.floor((new Date(limite).getTime() - agora.getTime()) / 60_000);
  const vencido = diferencaEmMinutos < 0;
  const minutos = Math.abs(diferencaEmMinutos);
  const horas = Math.floor(minutos / 60);
  const resto = minutos % 60;
  return { vencido, texto: horas > 0 ? `${horas} h ${resto} min` : `${resto} min` };
}
