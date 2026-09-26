import { AuthCard } from '@/features/auth/components/AuthCard';
import { EsqueciSenhaForm } from '@/features/auth/components/EsqueciSenhaForm';

export default function EsqueciSenhaPage() {
  return (
    <AuthCard titulo="Esqueci minha senha" descricao="Enviaremos um link para o seu e-mail.">
      <EsqueciSenhaForm />
    </AuthCard>
  );
}
