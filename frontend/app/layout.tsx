import type { Metadata, Viewport } from 'next';
import { Barlow, Barlow_Condensed, JetBrains_Mono } from 'next/font/google';
import type { ReactNode } from 'react';
import { Providers } from './providers';
import './globals.css';

const barlow = Barlow({ variable: '--font-barlow', subsets: ['latin'], weight: ['400', '500', '600'] });
const barlowCondensed = Barlow_Condensed({ variable: '--font-barlow-condensed', subsets: ['latin'], weight: ['700', '800'] });
const jetbrainsMono = JetBrains_Mono({ variable: '--font-jetbrains-mono', subsets: ['latin'], weight: ['400', '500'] });

export const metadata: Metadata = {
  title: 'Plataforma Fiscal',
  description: 'Emissão de documentos fiscais e CRM',
};

export const viewport: Viewport = { width: 'device-width', initialScale: 1 };

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="pt-BR" suppressHydrationWarning className={`${barlow.variable} ${barlowCondensed.variable} ${jetbrainsMono.variable}`}>
      <body className="min-h-dvh bg-background text-foreground antialiased">
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
