<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks;

use App\Modules\Fiscal\Domain\Exceptions\WebhookUrlInvalidaException;

/**
 * Defesa contra SSRF: só aceita URL que leva a um endereço público. Roda ao cadastrar e a cada envio, e o envio
 * se conecta a um dos IPs devolvidos aqui, de modo que o DNS não pode mudar entre a checagem e a conexão.
 */
final class UrlDeWebhookSegura
{
    private const MAX_URL = 500;

    public function __construct(private readonly ResolvedorDeDns $dns) {}

    public function validar(string $url): ValidacaoDeUrl
    {
        if ($url === '' || strlen($url) > self::MAX_URL) {
            throw new WebhookUrlInvalidaException('informe uma URL com até '.self::MAX_URL.' caracteres.');
        }
        if (preg_match('/[\s\\\\\x00-\x1f\x7f]/', $url) === 1) {
            throw new WebhookUrlInvalidaException('a URL não pode ter espaços, barras invertidas ou caracteres de controle.');
        }

        $partes = parse_url($url);
        if ($partes === false || ! isset($partes['scheme'], $partes['host'])) {
            throw new WebhookUrlInvalidaException('informe uma URL completa, como https://exemplo.com/webhook.');
        }

        $desenvolvimento = app()->environment(['local', 'testing']);
        $esquema = strtolower($partes['scheme']);
        if (! ($esquema === 'https' || ($esquema === 'http' && $desenvolvimento))) {
            throw new WebhookUrlInvalidaException('a URL precisa usar https.');
        }
        if (isset($partes['user']) || isset($partes['pass'])) {
            throw new WebhookUrlInvalidaException('a URL não pode ter usuário e senha.');
        }

        $porta = $partes['port'] ?? ($esquema === 'https' ? 443 : 80);
        if (filter_var($porta, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            throw new WebhookUrlInvalidaException('a porta precisa estar entre 1 e 65535.');
        }

        // Com ponto final o curl ignora o IP fixado e resolve o nome de novo (abre brecha para DNS rebinding).
        if (str_ends_with($partes['host'], '.')) {
            throw new WebhookUrlInvalidaException('o endereço do servidor não pode terminar com ponto.');
        }
        $host = strtolower($partes['host']);
        if ($host === '') {
            throw new WebhookUrlInvalidaException('a URL não tem um endereço válido.');
        }

        return new ValidacaoDeUrl($url, $host, $porta, $this->enderecos($host, $desenvolvimento));
    }

    /** @return list<string> */
    private function enderecos(string $host, bool $desenvolvimento): array
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $ipv6 = substr($host, 1, -1);
            if (filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new WebhookUrlInvalidaException('o endereço IPv6 é inválido.');
            }

            return $this->conferir([$ipv6], $desenvolvimento);
        }

        if ($host === 'localhost' && $desenvolvimento) {
            return ['127.0.0.1'];
        }

        $numerico = $this->ipv4Numerico($host);
        if ($numerico === false) {
            throw new WebhookUrlInvalidaException('o endereço IP é inválido.');
        }
        if ($numerico !== null) {
            return $this->conferir([$numerico], $desenvolvimento);
        }

        $ips = $this->dns->resolver($host);
        if ($ips === []) {
            throw new WebhookUrlInvalidaException('não foi possível resolver o endereço do servidor.');
        }

        return $this->conferir($ips, false);
    }

    /**
     * @param  list<string>  $ips
     * @return list<string>
     */
    private function conferir(array $ips, bool $aceitaLoopback): array
    {
        foreach ($ips as $ip) {
            if ($aceitaLoopback && $this->ehLoopback($ip)) {
                continue;
            }
            if (! $this->publico($ip)) {
                throw new WebhookUrlInvalidaException('a URL aponta para um endereço interno ou reservado, que não é aceito.');
            }
        }

        return $ips;
    }

    private function ehLoopback(string $ip): bool
    {
        $binario = @inet_pton($ip);

        return $binario !== false && (($binario === inet_pton('::1')) || (strlen($binario) === 4 && ord($binario[0]) === 127));
    }

    /**
     * Interpreta as formas que o sistema operacional aceita para IPv4 (decimal, hexadecimal, octal, abreviadas).
     *
     * @return string|false|null IP em notação pontuada; null quando o texto é um nome; false quando parece IP mas é inválido
     */
    private function ipv4Numerico(string $host): string|false|null
    {
        if (preg_match('/^(0x[0-9a-f]*|\d+)(\.(0x[0-9a-f]*|\d+)){0,3}$/i', $host) !== 1) {
            return null;
        }

        $partes = explode('.', $host);
        $valores = [];
        foreach ($partes as $parte) {
            if (strlen($parte) > 12) {
                return false;
            }
            if (stripos($parte, '0x') === 0) {
                if (strlen($parte) === 2) {
                    return false;
                }
                $valores[] = (int) hexdec(substr($parte, 2));
            } elseif (strlen($parte) > 1 && $parte[0] === '0') {
                if (preg_match('/^[0-7]+$/', $parte) !== 1) {
                    return false;
                }
                $valores[] = (int) octdec($parte);
            } else {
                $valores[] = (int) $parte;
            }
        }

        $ultimo = array_pop($valores);
        if ($ultimo >= 256 ** (4 - count($valores))) {
            return false;
        }
        $ip = $ultimo;
        foreach ($valores as $indice => $valor) {
            if ($valor > 255) {
                return false;
            }
            $ip += $valor * (256 ** (3 - $indice));
        }

        return long2ip($ip);
    }

    private function publico(string $ip): bool
    {
        $binario = @inet_pton($ip);
        if ($binario === false) {
            return false;
        }
        if (strlen($binario) === 4) {
            return $this->ipv4Publico($ip);
        }

        $bytes = array_values(unpack('C16', $binario) ?: []);
        $zerosIniciais = fn (int $n): bool => array_sum(array_slice($bytes, 0, $n)) === 0;

        if ($zerosIniciais(10) && $bytes[10] === 0xFF && $bytes[11] === 0xFF) {
            return $this->ipv4Publico(implode('.', array_slice($bytes, 12, 4)));
        }
        if ($zerosIniciais(12)) {
            return false; // ::, ::1 e IPv4 compatível
        }
        if (array_slice($bytes, 0, 4) === [0x00, 0x64, 0xFF, 0x9B] && array_sum(array_slice($bytes, 4, 8)) === 0) {
            return false; // NAT64 64:ff9b::/96
        }
        if ($bytes[0] === 0x20 && $bytes[1] === 0x02) {
            return $this->ipv4Publico(implode('.', array_slice($bytes, 2, 4))); // 6to4
        }
        if (($bytes[0] & 0xFE) === 0xFC || $bytes[0] === 0xFF) {
            return false; // fc00::/7 (ULA, inclui metadados da AWS) e multicast
        }
        if ($bytes[0] === 0xFE && ($bytes[1] & 0xC0) >= 0x80) {
            return false; // fe80::/10 e fec0::/10
        }
        if (array_slice($bytes, 0, 4) === [0x20, 0x01, 0x0D, 0xB8]) {
            return false; // documentação

        }

        return true;
    }

    private function ipv4Publico(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $n = ip2long($ip);
        if ($n === false) {
            return false;
        }
        $faixas = [['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['224.0.0.0', 4]];
        foreach ($faixas as [$base, $bits]) {
            $mascara = (-1 << (32 - $bits)) & 0xFFFFFFFF;
            if (($n & $mascara) === ((int) ip2long($base) & $mascara)) {
                return false;
            }
        }

        return true;
    }
}
