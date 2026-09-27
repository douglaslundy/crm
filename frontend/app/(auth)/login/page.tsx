import Link from 'next/link';
import { AuthCard } from '@/features/auth/components/AuthCard';
import { LoginForm } from '@/features/auth/components/LoginForm';

export default function LoginPage() {
  return (
    <AuthCard titulo="Entrar" descricao="Acesse a área da sua empresa.">
      <LoginForm />
      <p className="mt-4 text-center text-sm text-muted-foreground">
        Ainda não tem conta? <Link href="/planos" className="underline">Conheça os planos</Link>
      </p>
    </AuthCard>
  );
}
