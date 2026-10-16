/** WhatsApp comercial: DDI 55 + DDD 35 + número. */
export const NUMERO_WHATSAPP = '5535984297193';

export const MENSAGEM_PADRAO = 'Olá! Vim pelo site e quero conhecer a Plataforma Fiscal + CRM.';

export function linkWhatsapp(mensagem: string = MENSAGEM_PADRAO): string {
  return `https://wa.me/${NUMERO_WHATSAPP}?text=${encodeURIComponent(mensagem)}`;
}
