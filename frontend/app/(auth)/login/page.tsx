import { AuthCard } from '@/features/auth/components/AuthCard';
import { LoginForm } from '@/features/auth/components/LoginForm';

export default function LoginPage() {
  return (
    <AuthCard titulo="Entrar" descricao="Acesse a área da sua empresa.">
      <LoginForm />
    </AuthCard>
  );
}
