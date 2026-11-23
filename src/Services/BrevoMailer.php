<?php

namespace App\Services;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Envoie les emails transactionnels via l'API Brevo (POST /v3/smtp/email).
 *
 * @see https://developers.brevo.com/reference/sendtransacemail
 */
class BrevoMailer implements Mailer
{
    public const API_URL = 'https://api.brevo.com/v3/smtp/email';

    public function __construct(
        private ClientInterface $client,
        private string $apiKey,
        private string $fromEmail,
        private string $fromName,
    ) {}

    public function send(string $to, string $subject, string $html): void
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Clé API Brevo manquante (BREVO_API_KEY).');
        }

        try {
            $response = $this->client->request('POST', self::API_URL, [
                'headers' => [
                    'api-key' => $this->apiKey,
                    'accept' => 'application/json',
                ],
                'json' => [
                    'sender' => ['email' => $this->fromEmail, 'name' => $this->fromName],
                    'to' => [['email' => $to]],
                    'subject' => $subject,
                    'htmlContent' => $html,
                ],
                'http_errors' => false,
                'connect_timeout' => 5,
                'timeout' => 10,
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Appel à Brevo impossible : ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $body = json_decode((string) $response->getBody(), true);
            $reason = is_array($body) ? ($body['message'] ?? $body['code'] ?? null) : null;
            throw new RuntimeException(sprintf(
                'Email rejeté par Brevo (HTTP %d) : %s',
                $status,
                $reason ?? 'raison inconnue'
            ));
        }
    }
}
