<?php

namespace App\Support;

/**
 * Forma canônica de um valor de registro DNS que representa um nome (owner
 * name ou conteúdo de NS/CNAME/PTR/MX): minúsculas, sem ponto final.
 *
 * Existe para que a mesma zona nunca acabe com duas linhas equivalentes só
 * porque uma veio com "Host." e outra com "host" (import de BIND existente
 * traz FQDN com ponto final; edição manual e sincronização de nameservers
 * usam a forma sem ponto) — ver docs/KNOWN_ISSUES.md, DISC-001.
 */
class DnsRecordCanonicalizer
{
    /** Tipos cujo `content` é, ele mesmo, um nome DNS (não um IP nem texto livre). */
    public const NAME_CONTENT_TYPES = ['NS', 'CNAME', 'PTR', 'MX'];

    public static function name(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '' || $trimmed === '@') {
            return $trimmed;
        }

        return strtolower(rtrim($trimmed, '.'));
    }

    /**
     * Separa "10 mail.example.com." em prioridade + host. Sem prefixo
     * numérico reconhecível, assume prioridade 10 (mesma regra já usada na
     * importação de zonas descobertas).
     *
     * @return array{0: int, 1: string}
     */
    public static function splitMxContent(string $content): array
    {
        $parts = preg_split('/\s+/', trim($content), 2);

        if (count($parts) === 2 && ctype_digit($parts[0])) {
            return [(int) $parts[0], $parts[1]];
        }

        return [10, $content];
    }

    /**
     * Chave estável (tipo, nome, prioridade, conteúdo) para comparar um
     * registro vindo de fontes com grafias diferentes (banco já canonizado
     * vs. dump bruto do BIND) sem falso conflito. Quando `$priority` não é
     * informado, um `content` de MX no formato "10 host." é interpretado
     * como prioridade+host combinados (formato do dump do BIND); quando é
     * informado, `content` já é só o host (formato salvo no banco).
     */
    public static function comparisonKey(
        string $type,
        string $name,
        string $content,
        ?int $priority = null,
    ): string {
        $type = strtoupper($type);
        $canonicalName = self::name($name);
        $canonicalContent = $content;

        if ($type === 'MX') {
            if ($priority === null) {
                [$priority, $canonicalContent] = self::splitMxContent($content);
            }

            $canonicalContent = self::name($canonicalContent);
        } elseif (in_array($type, self::NAME_CONTENT_TYPES, true)) {
            $canonicalContent = self::name($content);
        }

        return implode('|', [$type, $canonicalName, $priority ?? '', $canonicalContent]);
    }
}
