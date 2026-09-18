<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente mínimo do R2 (API S3) com assinatura AWS SigV4. Só o necessário para
 * testar as credenciais: enviar, conferir e apagar um objeto minúsculo.
 */
class R2Client
{
    public function __construct(
        private readonly string $accountId,
        private readonly string $bucket,
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly ?string $endpointHost = null,
    ) {}

    public static function fromSettings(array $settings): self
    {
        return new self(
            $settings['account_id'],
            $settings['bucket'],
            $settings['access_key_id'],
            $settings['secret_access_key'],
        );
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function testConnection(string $prefix = ''): array
    {
        $key = ltrim($prefix, '/').'.conexao-teste-'.bin2hex(random_bytes(4)).'.txt';
        $body = 'teste de conexao do DNS Center '.gmdate('c');

        try {
            $put = $this->request('PUT', $key, $body);

            if (! $put->successful()) {
                return ['ok' => false, 'message' => $this->explain($put)];
            }

            $head = $this->request('HEAD', $key);
            $delete = $this->request('DELETE', $key);

            if (! $head->successful() || (int) $head->header('Content-Length') !== strlen($body)) {
                return ['ok' => false, 'message' => 'O envio foi aceito, mas a conferência do objeto falhou.'];
            }

            if (! $delete->successful()) {
                return ['ok' => false, 'message' => 'Enviar e ler funcionou, mas apagar o objeto de teste falhou; a permissão do token precisa ser leitura e gravação de objetos.'];
            }
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => 'Não foi possível conectar ao R2 (rede ou Account ID incorreto).'];
        }

        return ['ok' => true, 'message' => 'Conexão validada: o painel enviou, leu e apagou um objeto de teste no bucket.'];
    }

    public function request(string $method, string $key, string $body = '', ?string $amzDate = null): Response
    {
        $host = $this->endpointHost ?? "{$this->accountId}.r2.cloudflarestorage.com";
        $path = '/'.$this->bucket.'/'.implode('/', array_map('rawurlencode', explode('/', $key)));
        $payloadHash = hash('sha256', $body);
        $amzDate ??= gmdate('Ymd\THis\Z');

        $headers = self::sign(
            $method, $host, $path, '', ['x-amz-content-sha256' => $payloadHash],
            $payloadHash, $this->accessKeyId, $this->secretAccessKey, 'auto', $amzDate,
        );

        $pending = Http::withHeaders($headers)->timeout(10)->connectTimeout(5);

        return $method === 'PUT'
            ? $pending->withBody($body, 'text/plain')->put("https://{$host}{$path}")
            : $pending->send($method, "https://{$host}{$path}");
    }

    /**
     * Assinatura AWS SigV4 (serviço s3). Devolve os cabeçalhos a enviar.
     *
     * @param  array<string, string>  $extraHeaders  cabeçalhos assinados além de host e x-amz-date
     * @return array<string, string>
     */
    public static function sign(
        string $method,
        string $host,
        string $path,
        string $query,
        array $extraHeaders,
        string $payloadHash,
        string $accessKeyId,
        string $secretAccessKey,
        string $region,
        string $amzDate,
    ): array {
        $date = substr($amzDate, 0, 8);

        $headers = array_change_key_case($extraHeaders, CASE_LOWER);
        $headers['host'] = $host;
        $headers['x-amz-date'] = $amzDate;
        ksort($headers);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name.':'.trim($value)."\n";
        }
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = implode("\n", [$method, $path, $query, $canonicalHeaders, $signedHeaders, $payloadHash]);
        $scope = "{$date}/{$region}/s3/aws4_request";
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);

        $signingKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', 's3',
                hash_hmac('sha256', $region,
                    hash_hmac('sha256', $date, 'AWS4'.$secretAccessKey, true), true), true), true);

        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $sent = $headers;
        unset($sent['host']);
        $sent['Authorization'] = "AWS4-HMAC-SHA256 Credential={$accessKeyId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        return $sent;
    }

    private function explain(Response $response): string
    {
        return match ($response->status()) {
            401, 403 => 'O R2 recusou as credenciais (ID da chave, chave secreta ou permissão do token sobre este bucket).',
            404 => 'Bucket não encontrado nesta conta. Confira o nome do bucket e o Account ID.',
            default => 'O R2 respondeu com erro '.$response->status().'.',
        };
    }
}
