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
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Monolog\Logger;

use function OAuth\PKCE\generatePair;

class ApiWrapper
{
    private Client $client;

    public function __construct(
        private AccessTokenRepository $accessTokenRepository,
        private AuthorizationCodeRepository $authorizationCodeRepository,
        private string $haAuthUrl,
        private string $apiUrl,
        private string $apiAuthUrl,
        private string $clientId,
        private string $clientSecret,
        private string $webSiteDomain,
        private Logger $apiLogger,
    ) {
        $this->client = new Client();
    }

    /**
     * Exécute une requête HTTP via Guzzle avec gestion d'erreur centralisée.
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function httpRequest(string $method, string $url, array $options, string $errorContext): \Psr\Http\Message\ResponseInterface
    {
        try {
            return $this->client->request($method, $url, $options);
        } catch (RequestException $e) {
            $this->apiLogger->error("Erreur lors de {$errorContext}: " . $e->getMessage());
            if ($e->hasResponse()) {
                $this->apiLogger->error('Response body: ' . $e->getResponse()->getBody());
            }
            throw new Exception("Erreur lors de {$errorContext} : " . $e->getMessage(), 0, $e);
        } catch (GuzzleException $e) {
            $this->apiLogger->error("Erreur Guzzle lors de {$errorContext}: " . $e->getMessage());
            throw new Exception("Erreur de connexion à l'API : " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Décode la réponse JSON et vérifie sa validité.
     */
    private function decodeJsonResponse(\Psr\Http\Message\ResponseInterface $response): array
    {
        $data = json_decode($response->getBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Erreur de décodage JSON : " . json_last_error_msg());
        }
        return $data;
    }


    /**
     * Génère un token d'accès global en utilisant le flux client_credentials, et le stocke en base de données.
     *
     * @return AccessToken
     */
    private function generateGlobalAccessToken(): AccessToken
    {
        $response = $this->httpRequest('POST', $this->apiAuthUrl, [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
            'headers' => [
                'content-type' => 'application/x-www-form-urlencoded',
                'accept' => 'application/json',
            ],
        ], 'la génération du token global');

        $responseData = $this->decodeJsonResponse($response);

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
       
        $current_access_token = $this->accessTokenRepository->selectBySlug(null);

        if($current_access_token) {
                $obj->id = $current_access_token->id;
                $obj = $this->accessTokenRepository->update($obj);
                $this->apiLogger->info('Global access token refreshed successfully. it will expires at '.$obj->refresh_token_expires_at->format('Y-m-d H:i:s'));
        } else {
            $obj = $this->accessTokenRepository->insert($obj);
            $this->apiLogger->info('New global access token generated successfully. it will expires at '.$obj->refresh_token_expires_at->format('Y-m-d H:i:s'));
        }

        return $obj;
    }

    /**
     * Rafraîchit un token d'accès pour une organisation donnée en utilisant le refresh token, et met à jour la base de données.
     *
     * @param string $refreshToken
     * @param string $organization_slug
     * @return AccessToken
     */
    public function refreshToken(string $refreshToken, string $organizationSlug): ?AccessToken
    {
        $response = $this->httpRequest('POST', $this->apiAuthUrl, [
            'form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ],
            'headers' => [
                'content-type' => 'application/x-www-form-urlencoded',
                'accept' => 'application/json',
            ],
        ], "le refresh token pour {$organizationSlug}");

        $responseData = $this->decodeJsonResponse($response);

        if (!isset($responseData['access_token']) || !isset($responseData['refresh_token'])) {
            throw new Exception("Erreur : Les tokens ne sont pas présents dans la réponse.");
        }

        $accessTokenExpiresAt = (new DateTime())->add(new DateInterval('PT28M'));
        $refreshTokenExpiresAt = (new DateTime())->add(new DateInterval('P28D'));

        $obj = new AccessToken();
        $obj->access_token = $responseData['access_token'];
        $obj->refresh_token = $responseData['refresh_token'];
        $obj->organization_slug = $organizationSlug;
        $obj->access_token_expires_at = $accessTokenExpiresAt;
        $obj->refresh_token_expires_at = $refreshTokenExpiresAt;
        $this->apiLogger->info('New organisation access token generated successfully. it will expires at ' . $obj->access_token_expires_at->format('Y-m-d H:i:s'));
        return $this->accessTokenRepository->update($obj);
    }
  
    /**
     * Récupère le token d'accès global ou pour une organisation donnée, et le rafraîchit si nécessaire.
     * 
     * @return AccessToken
     */
    public function getGlobalAccessToken(): AccessToken
    {
        $tokenData = $this->accessTokenRepository->selectBySlug(null);
        
        $expiration_access_date = $tokenData->access_token_expires_at ?? false;

        // si null ou expiré, on génère un nouveau token global
        $this->apiLogger->info('Check expiration for global access token');
        if ($this->isExpired($expiration_access_date) || $tokenData == null) {
            $this->apiLogger->debug('Global access token is invalid. Attempting to generate new one.');
            $tokenData = $this->generateGlobalAccessToken();
        }
        $this->apiLogger->info('Global access token is valid. Expiry time: ' . 
        ($tokenData->access_token_expires_at instanceof DateTime ? $tokenData->access_token_expires_at->format('Y-m-d H:i:s') : $tokenData->access_token_expires_at));

        return $tokenData;
        
    }

    /**
     * Récupère le token d'accès pour une organisation donnée.
     *
     * @param string $organization_slug
     * @return AccessToken|null
     */
    public function getOrganizationAccessToken(string $organizationSlug): AccessToken
    {
        $tokenData = $this->accessTokenRepository->selectBySlug($organizationSlug);

        if ($tokenData === null) {
            $this->apiLogger->error('Aucun token trouvé pour organization_slug: ' . $organizationSlug);
            throw new Exception('Aucun token trouvé pour l\'organisation: ' . $organizationSlug);
        }

        $this->apiLogger->info('Check expiration for access token of organization_slug: ' . $organizationSlug);

        if ($this->isExpired($tokenData->refresh_token_expires_at ?? false)) {
            $this->apiLogger->error('Refresh token is expired for organization_slug: ' . $organizationSlug);
            throw new Exception('Invalid token data: refresh_token is expired');
        }

        if ($this->isExpired($tokenData->access_token_expires_at ?? false)) {
            $this->apiLogger->debug('Access token for organization_slug: ' . $organizationSlug . ' is expired. Attempting to refresh token.');
            $tokenData = $this->refreshToken($tokenData->refresh_token, $organizationSlug);
            $this->apiLogger->info('Access token refreshed for organization_slug: ' . $organizationSlug . '. New expiry time: ' .
                ($tokenData->access_token_expires_at instanceof \DateTime ? $tokenData->access_token_expires_at->format('Y-m-d H:i:s') : $tokenData->access_token_expires_at));
        }

        return $tokenData;
    }

    /**
     * Vérifie si une date d'expiration est dépassée par rapport à la date actuelle.
     *
     * @param [type] $expirationDate
     * @return boolean
     */
    private function isExpired(string|\DateTime|false $expirationDate): bool
    {
        if (!$expirationDate) {
            return true;
        }
        $expiration = is_string($expirationDate) ? new \DateTime($expirationDate) : $expirationDate;
        $now = new \DateTime();
        $this->apiLogger->debug('Current time: ' . $now->format('Y-m-d H:i:s'));
        $this->apiLogger->debug('Token expiry time: ' . $expiration->format('Y-m-d H:i:s'));
        return $expiration < $now;
    }

    /**
     * Génère une URL d'autorisation pour une organisation donnée.
     *
     * @param string|null $organizationSlug Slug de l'orga (null si inconnu au moment de l'init, e.g. flux de création de stream)
     * @param string|null $redirectUri URI de redirection personnalisée (utilise /validate_auth_page par défaut)
     * @return string
     */
    public function generateAuthorizationUrl(?string $organizationSlug, ?string $redirectUri = null): string
    {
        $uniqueUUID = bin2hex(random_bytes(16));
        $pair = generatePair(128);
        $codeVerifier = $pair->getVerifier();
        $redirectUri = $redirectUri ?? "$this->webSiteDomain/validate_auth_page";

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

    /**
     * Récupère la liste des formulaires de don d'une organisation.
     *
     * @param string $organizationSlug
     * @return array
     */
    public function getDonationForms(string $organizationSlug): array
    {
        $tokenData = $this->getOrganizationAccessToken($organizationSlug);

        $response = $this->httpRequest('GET', "{$this->apiUrl}/organizations/{$organizationSlug}/forms", [
            'query' => [
                'formTypes' => 'Donation',
                'pageSize' => 50,
            ],
            'headers' => [
                'Authorization' => 'Bearer ' . $tokenData->access_token,
                'accept' => 'application/json',
            ],
        ], "la récupération des formulaires de don pour {$organizationSlug}");

        $data = $this->decodeJsonResponse($response);

        return $data['data'] ?? [];
    }

    /**
     * Configure le domaine du client API pour une organisation donnée en utilisant un token d'accès valide.
     *
     * @param [type] $accessToken
     * @return void
     */
    public function setClientDomain(string $accessToken): void
    {
        $this->httpRequest('PUT', "{$this->apiUrl}/partners/me/api-clients", [
            'body' => json_encode(["Domain" => $this->webSiteDomain]),
            'headers' => [
                'content-type' => 'application/*+json',
                'accept' => 'application/json',
                'Authorization' => "Bearer {$accessToken}",
            ],
        ], 'la configuration du domaine client');
    }

    /**
     * Stocke ou met à jour un AccessToken à partir des données retournées par l'échange OAuth.
     */
    public function storeOrUpdateToken(array $tokenData): AccessToken
    {
        $organizationSlug = $tokenData['organization_slug'];
        $existingToken = $this->accessTokenRepository->selectBySlug($organizationSlug);

        $token = new AccessToken();
        $token->access_token = $tokenData['access_token'];
        $token->refresh_token = $tokenData['refresh_token'];
        $token->organization_slug = $organizationSlug;
        $token->access_token_expires_at = (new DateTime())->add(new DateInterval('PT28M'));
        $token->refresh_token_expires_at = (new DateTime())->add(new DateInterval('P28D'));

        if ($existingToken === null) {
            $this->accessTokenRepository->insert($token);
        } else {
            $this->accessTokenRepository->update($token);
        }

        return $token;
    }

    /**
     * Échange un code d'autorisation contre un token d'accès pour une organisation donnée, et stocke les tokens en base de données.
     *
     * @param [type] $code
     * @param [type] $redirect_uri
     * @param [type] $codeVerifier
     * @return void
     */
    public function exchangeAuthorizationCode(string $code, string $redirectUri, string $codeVerifier): array
    {
        $response = $this->httpRequest('POST', $this->apiAuthUrl, [
            'form_params' => [
                'grant_type' => 'authorization_code',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $codeVerifier,
            ],
            'headers' => [
                'content-type' => 'application/x-www-form-urlencoded',
                'accept' => 'application/json',
            ],
        ], "l'échange du code d'autorisation");

        $responseData = $this->decodeJsonResponse($response);

        if (
            !isset($responseData['access_token']) ||
            !isset($responseData['refresh_token']) ||
            !isset($responseData['expires_in']) ||
            !isset($responseData['organization_slug'])
        ) {
            throw new Exception("Erreur : Les tokens ne sont pas présents dans la réponse.");
        }

        return $responseData;
    }

    /**
     * Récupère tous les dons pour un formulaire de don donné, en gérant la pagination avec les continuation tokens.
     *
     * @param [type] $organizationSlug
     * @param [type] $donationSlug
     * @param [type] $accessToken
     * @param [type] $continuationToken
     * @return array
     */
    private function getDonationFormOrders(string $organizationSlug, string $donationSlug, string $accessToken, ?string $continuationToken = null): array
    {
        $query = ['withDetails' => 'true', 'sortOrder' => 'asc'];
        if ($continuationToken) {
            $query['continuationToken'] = $continuationToken;
        }

        $response = $this->httpRequest(
            'GET',
            "{$this->apiUrl}/organizations/{$organizationSlug}/forms/donation/{$donationSlug}/orders",
            [
                'query' => $query,
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'accept' => 'application/json',
                ],
            ],
            "la récupération des commandes pour {$organizationSlug}/{$donationSlug}",
        );

        return json_decode($response->getBody(), true);
    }
    
    /**
     * Récupère tous les dons pour un formulaire de don donné, en gérant la pagination avec les continuation tokens, et en rafraîchissant le token d'accès si nécessaire.
     *
     * @param [type] $organizationSlug
     * @param [type] $formSlug
     * @param integer $currentAmount
     * @param [type] $continuationToken
     * @return array
     */
    public function getAllOrders(string $organizationSlug, string $formSlug, int $currentAmount = 0, ?string $continuationToken = null): array
    {
        $previousToken = '';
        $donations = [];

        try {
            $organizationAccessToken = $this->getOrganizationAccessToken($organizationSlug);
        } catch (Exception $e) {
            throw new Exception('Votre token d\'accès pour l\'organisation ' . $organizationSlug . ' est expiré ou invalide. Veuillez vous reconnecter pour renouveler votre token.', 401, $e);
        }

        if (!$organizationAccessToken || !isset($organizationAccessToken->access_token)) {
            throw new Exception('Jeton d\'accès API non trouvé ou expiré pour l\'organisation ' . $organizationSlug . '.', 401);
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

                $donations[] = [
                    "pseudo" => $pseudo,
                    "message" => $message,
                    "amount" => $amount,
                ];
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