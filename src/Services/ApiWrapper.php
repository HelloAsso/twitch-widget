<?php

namespace App\Services;

use App\Models\AccessToken;
use App\Models\AuthorizationCode;
use App\Repositories\AccessTokenRepository;
use App\Repositories\AuthorizationCodeRepository;
use DateInterval;
use DateTime;
use Exception;
use GuzzleHttp\Client;

use function OAuth\PKCE\generatePair;

class ApiWrapper
{
    private $client;

    public function __construct(
        private AccessTokenRepository $accessTokenRepository,
        private AuthorizationCodeRepository $authorizationCodeRepository,
        private string $haAuthUrl,
        private string $apiUrl,
        private string $apiAuthUrl,
        private string $clientId,
        private string $clientSecret,
        private string $webSiteDomain
    ) {
        $this->client = new Client();
    }

    private function generateGlobalAccessToken(): AccessToken
    {
        $response = $this->client->request('POST', $this->apiAuthUrl, [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret
            ],
            'headers' => [
                'content-type' => 'application/x-www-form-urlencoded',
                'accept' => 'application/json',
            ],
        ]);

        $responseData = json_decode($response->getBody(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Erreur de décodage JSON : " . json_last_error_msg());
        }

        if (!isset($responseData['access_token']) || !isset($responseData['refresh_token'])) {
            throw new Exception("Erreur : Les tokens ne sont pas présents dans la réponse.");
        }

        $accessTokenExpiresAt = (new DateTime())->add(new DateInterval('PT1700S'));
        $refreshTokenExpiresAt = (new DateTime())->add(new DateInterval('P29D'));

        $obj = new AccessToken();
        $obj->access_token = $responseData['access_token'];
        $obj->refresh_token = $responseData['refresh_token'];
        $obj->access_token_expires_at = $accessTokenExpiresAt;
        $obj->refresh_token_expires_at = $refreshTokenExpiresAt;

        $obj = $this->accessTokenRepository->insert($obj);

        return $obj;
    }

    private function refreshToken($refreshToken, $organization_slug): ?AccessToken
    {
        $response = $this->client->request('POST', $this->apiAuthUrl, [
            'form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ],
            'headers' => [
                'content-type' => 'application/x-www-form-urlencoded',
                'accept' => 'application/json',
            ],
        ]);

        $responseData = json_decode($response->getBody(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Erreur de décodage JSON : " . json_last_error_msg());
        }

        if (!isset($responseData['access_token']) || !isset($responseData['refresh_token'])) {
            throw new Exception("Erreur : Les tokens ne sont pas présents dans la réponse.");
        }

        $accessTokenExpiresAt = (new DateTime())->add(new DateInterval('PT28M'));
        $refreshTokenExpiresAt = (new DateTime())->add(new DateInterval('P28D'));

        $obj = new AccessToken();
        $obj->access_token = $responseData['access_token'];
        $obj->refresh_token = $responseData['refresh_token'];
        $obj->organization_slug = $organization_slug;
        $obj->access_token_expires_at = $accessTokenExpiresAt;
        $obj->refresh_token_expires_at = $refreshTokenExpiresAt;

        return $this->accessTokenRepository->update(
            $obj
        );
    }

    public function getAccessTokensAndRefreshIfNecessary($organization_slug): ?AccessToken
    {
        $tokenData = $this->accessTokenRepository->selectBySlug($organization_slug);

        if ($tokenData == null) {
            if ($organization_slug == null) {
                $tokenData = $this->generateGlobalAccessToken();
                return $tokenData;
            } else {
                return null;
            }
        } else {
            if ($tokenData->access_token_expires_at < new DateTime()) {
                $tokenData = $this->refreshToken($tokenData->refresh_token, $organization_slug);
                return $tokenData;
            }
            return $tokenData;
        }
    }

    public function generateAuthorizationUrl($organizationSlug)
    {
        $uniqueUUID = bin2hex(random_bytes(16));
        $pair = generatePair(128);
        $codeVerifier = $pair->getVerifier();
        $redirectUri = "$this->webSiteDomain/validate_auth_page";

        $authorizationCode = new AuthorizationCode();
        $authorizationCode->id = $uniqueUUID;
        $authorizationCode->code_verifier = $codeVerifier;
        $authorizationCode->organization_slug = $organizationSlug;
        $authorizationCode->redirect_uri = $redirectUri;

        $this->authorizationCodeRepository->insert($authorizationCode);

        $codeChallenge = $pair->getChallenge();

        $authorizationUrl = $this->haAuthUrl . "/authorize?" . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => $uniqueUUID
        ]);

        return $authorizationUrl;
    }

    public function setClientDomain($accessToken)
    {
        $this->client->request('PUT', "$this->apiUrl/partners/me/api-clients", [
            'body' => json_encode([
                "Domain" => $this->webSiteDomain
            ]),
            'headers' => [
                'content-type' => 'application/*+json',
                'accept' => 'application/json',
                'Authorization' => "Bearer $accessToken",
            ],
        ]);
    }

    public function exchangeAuthorizationCode($code, $redirect_uri, $codeVerifier)
    {
        $response = $this->client->request('POST', $this->apiAuthUrl, [
            'form_params' => [
                'grant_type' => 'authorization_code',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $code,
                'redirect_uri' => $redirect_uri,
                'code_verifier' => $codeVerifier
            ],
            'headers' => [
                'content-type' => 'application/x-www-form-urlencoded',
                'accept' => 'application/json',
            ],
        ]);

        $responseData = json_decode($response->getBody(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Erreur de décodage JSON : " . json_last_error_msg());
        }

        if (
            !isset($responseData['access_token']) ||
            !isset($responseData['refresh_token']) ||
            !isset($responseData['expires_in']) ||
            !isset($responseData['organization_slug'])
        ) {
            print_r($responseData);
            throw new Exception("Erreur : Les tokens ne sont pas présents dans la réponse.");
        }

        return $responseData;
    }

    private function getDonationFormOrders($organizationSlug, $donationSlug, $accessToken, $continuationToken = null)
    {
        $curl = curl_init();

        $url = $this->apiUrl . '/organizations/' . $organizationSlug . '/forms/donation/' . $donationSlug . '/orders?withDetails=true&sortOrder=asc';
        if ($continuationToken) {
            $url .= '&continuationToken=' . $continuationToken;
        }

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $accessToken
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);

        $response_data = json_decode($response, true);
        return $response_data;
    }

    public function getAllOrders($organizationSlug, $formSlug, $currentAmount = 0, $continuationToken = null)
    {
        $previousToken = '';
        $donations = [];

        $organizationAccessToken = $this->getAccessTokensAndRefreshIfNecessary($organizationSlug);

        if (!$organizationAccessToken || !isset($organizationAccessToken->access_token)) {
            http_response_code(401);
            echo json_encode(['error' => 'Jeton d\'accès API non trouvé ou expiré.']);
            exit;
        }

        do {
            $formOrdersData = $this->getDonationFormOrders(
                $organizationSlug,
                $formSlug,
                $organizationAccessToken->access_token,
                $continuationToken
            );

            if (!isset($formOrdersData['data'])) {
                break;
            }

            foreach ($formOrdersData['data'] as $order) {

                $pseudo = "anonyme";
                $message = "";

                foreach ($order['items'] as $item) {
                    if (array_key_exists('customFields', $item)) {
                        foreach ($item['customFields'] as $field) {
                            if (strcasecmp($field['name'], 'pseudo') == 0) {
                                $pseudo = $field['answer'];
                            }
                            if (strcasecmp($field['name'], 'message') == 0) {
                                $message = $field['answer'];
                            }
                        }
                    }
                }

                $amount = isset($order['amount']['total']) && is_numeric($order['amount']['total']) ? $order['amount']['total'] : 0;
                $currentAmount += $amount;

                $donation = [
                    "pseudo" => $pseudo,
                    "message" => $message,
                    "amount" => $amount
                ];

                array_push($donations, $donation);
            }

            $previousToken = $continuationToken;
            $continuationToken = $formOrdersData['pagination']['continuationToken'] ?? null;
        } while ($continuationToken && $continuationToken !== $previousToken);

        return [
            'amount' => $currentAmount,
            'donations' => $donations,
            'continuation_token' => $continuationToken
        ];
    }
}
