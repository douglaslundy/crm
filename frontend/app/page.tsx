import type { Metadata } from 'next';
import { Landing } from '@/features/landing/components/Landing';

export const metadata: Metadata = {
  title: 'Plataforma Fiscal + CRM: emita NF-e, NFC-e e NFS-e e venda mais',
  description: 'Emissão de notas fiscais e CRM no mesmo lugar: funil de vendas, propostas com aceite online, API e webhooks.',
};

export default function Home() {
  return <Landing />;
}
