import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export function AguardandoAtivacao() {
  return (
    <Card className="mx-auto max-w-lg">
      <CardHeader>
        <CardTitle><h1>Aguardando ativação</h1></CardTitle>
      </CardHeader>
      <CardContent className="space-y-2 text-muted-foreground">
        <p>Recebemos seu cadastro. Assim que a conta for ativada, você terá acesso completo.</p>
        <p>Se tiver dúvidas, responda ao e-mail de boas-vindas.</p>
      </CardContent>
    </Card>
  );
}
