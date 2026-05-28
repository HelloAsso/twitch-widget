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

    /**
     * Fichier partagé pour tracer les appels auth (rate limiting inter-processus).
     */
    private string $authRateLimitFile;

    /**
     * Règles de rate limit HelloAsso pour l'API d'authentification.
     * Chaque règle : [max_calls, window_seconds]
     */
    private const AUTH_RATE_LIMITS = [
        [10, 10],      // Règle #1 : 10 appels / 10 secondes
        [20, 600],     // Règle #2 : 20 appels / 10 minutes
        [50, 3600],    // Règle #3 : 50 appels / heure
    ];

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
        $this->authRateLimitFile = sys_get_temp_dir() . '/twitch_widget_auth_rate_limit.json';
    }

    /**
     * Exécute une requête HTTP via Guzzle avec gestion d'erreur centralisée.
     * Gère le rate limiting (HTTP 429) avec retry automatique.
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function httpRequest(string $method, string $url, array $options, string $errorContext): \Psr\Http\Message\ResponseInterface
    {
        $maxRetries = 2;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->client->request($method, $url, $options);

                // Log si on approche du rate limit
                $remaining = $response->getHeaderLine('X-RateLimit-Remaining');
                if ($remaining !== '' && (int) $remaining <= 5) {
                    $this->apiLogger->warning("Rate limit proche pour {$errorContext} : {$remaining} requêtes restantes.");
                }

                return $response;
            } catch (RequestException $e) {
                // Gestion HTTP 429 — Too Many Requests
                if ($e->hasResponse() && $e->getResponse()->getStatusCode() === 429) {
                    $retryAfter = (int) ($e->getResponse()->getHeaderLine('Retry-After') ?: 5);
                    $retryAfter = min($retryAfter, 30); // Cap à 30 secondes
                    $this->apiLogger->warning("Rate limit atteint pour {$errorContext}. Retry dans {$retryAfter}s (tentative " . ($attempt + 1) . "/{$maxRetries}).");

                    if ($attempt < $maxRetries) {
                        sleep($retryAfter);
                        continue;
                    }
                }

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

        // Ne devrait jamais être atteint
        throw new Exception("Erreur inattendue lors de {$errorContext}.");
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
        $this->throttleAuthCall();

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
     * Le token est identifié par son ID pour un update atomique (pas de re-select).
     *
     * @param AccessToken $currentToken Le token actuel (avec id, refresh_token)
     * @return AccessToken
     */
    public function refreshToken(AccessToken $currentToken): AccessToken
    {
        $organizationSlug = $currentToken->organization_slug ?? 'global';

        $this->throttleAuthCall();

        $response = $this->httpRequest('POST', $this->apiAuthUrl, [
            'form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $currentToken->refresh_token,
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

        $currentToken->access_token = $responseData['access_token'];
        $currentToken->refresh_token = $responseData['refresh_token'];
        $currentToken->access_token_expires_at = (new DateTime())->add(new DateInterval('PT28M'));
        $currentToken->refresh_token_expires_at = (new DateTime())->add(new DateInterval('P28D'));

        return $this->accessTokenRepository->updateById($currentToken);
    }
  
    /**
     * Récupère le token d'accès global, et le régénère si nécessaire.
     * Utilise un verrou DB pour éviter les régénérations concurrentes.
     *
     * @return AccessToken
     */
    public function getGlobalAccessToken(): AccessToken
    {
        $tokenData = $this->accessTokenRepository->selectBySlug(null);

        if ($tokenData != null && !$this->isExpired($tokenData->access_token_expires_at ?? false)) {
            return $tokenData;
        }

        // Token absent ou expiré → verrouiller pour sérialiser
        $pdo = $this->accessTokenRepository->getPdo();
        $pdo->beginTransaction();

        try {
            $lockedToken = $this->accessTokenRepository->selectBySlugForUpdate(null);

            // Un autre process a peut-être déjà régénéré le token
            if ($lockedToken != null && !$this->isExpired($lockedToken->access_token_expires_at ?? false)) {
                $pdo->commit();
                return $lockedToken;
            }

            $this->apiLogger->info('Global access token absent ou expiré, génération d\'un nouveau.');
            $tokenData = $this->generateGlobalAccessToken();
            $pdo->commit();
            return $tokenData;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Récupère le token d'accès pour une organisation donnée.
     * Utilise un verrou DB (SELECT ... FOR UPDATE) pour éviter que plusieurs processus
     * ne rafraîchissent le même token simultanément, ce qui invaliderait les refresh tokens.
     *
     * @param string $organizationSlug
     * @return AccessToken
     */
    public function getOrganizationAccessToken(string $organizationSlug): AccessToken
    {
        // Lecture rapide sans verrou — si le token est valide, pas besoin de transaction
        $tokenData = $this->accessTokenRepository->selectBySlug($organizationSlug);

        if ($tokenData === null) {
            $this->apiLogger->error('Aucun token trouvé pour organization_slug: ' . $organizationSlug);
            throw new Exception('Aucun token trouvé pour l\'organisation: ' . $organizationSlug);
        }

        if ($this->isExpired($tokenData->refresh_token_expires_at ?? false)) {
            $this->apiLogger->error('Refresh token expiré pour organization_slug: ' . $organizationSlug);
            throw new Exception('Invalid token data: refresh_token is expired');
        }

        // Access token encore valide → on l'utilise directement
        if (!$this->isExpired($tokenData->access_token_expires_at ?? false)) {
            return $tokenData;
        }

        // Access token expiré → verrouiller la ligne en DB pour sérialiser les refresh concurrents
        $pdo = $this->accessTokenRepository->getPdo();
        $pdo->beginTransaction();

        try {
            // SELECT ... FOR UPDATE : bloque les autres processus sur cette ligne
            $lockedToken = $this->accessTokenRepository->selectBySlugForUpdate($organizationSlug);

            if ($lockedToken === null) {
                $pdo->rollBack();
                throw new Exception('Aucun token trouvé pour l\'organisation: ' . $organizationSlug);
            }

            // Après le verrou, un autre process a peut-être déjà rafraîchi le token
            if (!$this->isExpired($lockedToken->access_token_expires_at ?? false)) {
                $pdo->commit();
                $this->apiLogger->debug('Token déjà rafraîchi par un autre process pour ' . $organizationSlug);
                return $lockedToken;
            }

            // Toujours expiré → on rafraîchit (on est le seul à pouvoir le faire grâce au verrou)
            $this->apiLogger->info('Rafraîchissement du token pour organization_slug: ' . $organizationSlug);
            $refreshedToken = $this->refreshToken($lockedToken);
            $pdo->commit();

            $this->apiLogger->info('Token rafraîchi pour ' . $organizationSlug . '. Nouvelle expiration : ' .
                ($refreshedToken->access_token_expires_at instanceof \DateTime
                    ? $refreshedToken->access_token_expires_at->format('Y-m-d H:i:s')
                    : $refreshedToken->access_token_expires_at));
            return $refreshedToken;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Le refresh a échoué — peut-être qu'un autre process a déjà utilisé le refresh token
            // avant notre verrou. On attend un court instant et on re-lit la DB.
            usleep(500_000); // 500ms
            $retryToken = $this->accessTokenRepository->selectBySlug($organizationSlug);
            if ($retryToken && !$this->isExpired($retryToken->access_token_expires_at ?? false)) {
                $this->apiLogger->info('Token récupéré après échec refresh (rafraîchi par un autre process) pour ' . $organizationSlug);
                return $retryToken;
            }

            $this->apiLogger->error('Échec du refresh token pour ' . $organizationSlug . ' : ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Vérifie si une date d'expiration est dépassée par rapport à la date actuelle.
     */
    private function isExpired(string|\DateTime|false $expirationDate): bool
    {
        if (!$expirationDate) {
            return true;
        }
        $expiration = is_string($expirationDate) ? new \DateTime($expirationDate) : $expirationDate;
        return $expiration < new \DateTime();
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
     * Récupère la liste des formulaires d'une organisation pour les types donnés.
     *
     * @param string $organizationSlug
     * @param array $formTypes Liste des types de formulaires à récupérer (ex: ['Donation', 'CrowdFunding'])
     * @return array
     */
    public function getOrganizationForms(string $organizationSlug, array $formTypes = ['Donation', 'CrowdFunding']): array
    {
        $tokenData = $this->getOrganizationAccessToken($organizationSlug);

        $response = $this->httpRequest('GET', "{$this->apiUrl}/organizations/{$organizationSlug}/forms", [
            'query' => [
                'formTypes' => implode(',', $formTypes),
                'pageSize' => 50,
            ],
            'headers' => [
                'Authorization' => 'Bearer ' . $tokenData->access_token,
                'accept' => 'application/json',
            ],
        ], "la récupération des formulaires pour {$organizationSlug}");

        $data = $this->decodeJsonResponse($response);

        return $data['data'] ?? [];
    }

    /**
     * Récupère la liste des formulaires de don d'une organisation.
     *
     * @param string $organizationSlug
     * @return array
     */
    public function getDonationForms(string $organizationSlug): array
    {
        return $this->getOrganizationForms($organizationSlug, ['Donation']);
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
        $this->throttleAuthCall();

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
    private function getDonationFormOrders(string $organizationSlug, string $donationSlug, string $accessToken, ?string $continuationToken = null, string $formType = 'Donation'): array
    {
        $query = ['withDetails' => 'true', 'sortOrder' => 'asc', 'pageSize' => 100];
        if ($continuationToken) {
            $query['continuationToken'] = $continuationToken;
        }

        $formTypePath = $formType ?: 'Donation';

        $response = $this->httpRequest(
            'GET',
            "{$this->apiUrl}/organizations/{$organizationSlug}/forms/{$formTypePath}/{$donationSlug}/orders",
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
    public function getAllOrders(string $organizationSlug, string $formSlug, int $currentAmount = 0, ?string $continuationToken = null, string $formType = 'Donation'): array
    {
        $previousToken = '';
        $donations = [];

        try {
            $organizationAccessToken = $this->getOrganizationAccessToken($organizationSlug);
        } catch (Exception $e) {
            $isTokenError = str_contains($e->getMessage(), 'token')
                || str_contains($e->getMessage(), 'Token')
                || str_contains($e->getMessage(), 'expired')
                || $e->getCode() === 401;
            if ($isTokenError) {
                throw new Exception('Votre token d\'accès pour l\'organisation ' . $organizationSlug . ' est expiré ou invalide. Veuillez vous reconnecter pour renouveler votre token.', 401, $e);
            }
            throw new Exception('Erreur lors de la récupération du token pour l\'organisation ' . $organizationSlug . ' : ' . $e->getMessage(), 0, $e);
        }

        if (!$organizationAccessToken || !isset($organizationAccessToken->access_token)) {
            throw new Exception('Jeton d\'accès API non trouvé ou expiré pour l\'organisation ' . $organizationSlug . '.', 401);
        }

        do {
            $formOrdersData = $this->getDonationFormOrders(
                $organizationSlug,
                $formSlug,
                $organizationAccessToken->access_token,
                $continuationToken,
                $formType
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

            // Pause entre les pages pour respecter le rate limit HelloAsso
            if ($continuationToken && $continuationToken !== $previousToken) {
                usleep(200_000); // 200ms entre chaque page
            }
        } while ($continuationToken && $continuationToken !== $previousToken);

        return [
            'amount' => $currentAmount,
            'donations' => $donations,
            'continuation_token' => $continuationToken
        ];
    }

    /**
     * Vérifie et applique le rate limiting pour les appels à l'API d'authentification HelloAsso.
     * Utilise un fichier partagé pour coordonner entre les processus PHP concurrents.
     *
     * Règles :
     * - 10 appels max toutes les 10 secondes
     * - 20 appels max toutes les 10 minutes
     * - 50 appels max par heure
     *
     * @throws Exception si le rate limit est atteint et ne peut pas être résolu par attente
     */
    private function throttleAuthCall(): void
    {
        $maxWait = 15; // Attente max en secondes avant d'abandonner
        $waited = 0;

        while ($waited < $maxWait) {
            $timestamps = $this->readAuthTimestamps();
            $now = microtime(true);

            // Nettoyer les timestamps de plus d'1 heure (plus grande fenêtre)
            $timestamps = array_values(array_filter($timestamps, fn($ts) => ($now - $ts) < 3600));

            $waitNeeded = 0;

            foreach (self::AUTH_RATE_LIMITS as [$maxCalls, $windowSeconds]) {
                $windowStart = $now - $windowSeconds;
                $callsInWindow = count(array_filter($timestamps, fn($ts) => $ts >= $windowStart));

                if ($callsInWindow >= $maxCalls) {
                    // Calculer combien de temps attendre pour que le plus ancien appel sorte de la fenêtre
                    $oldestInWindow = array_values(array_filter($timestamps, fn($ts) => $ts >= $windowStart));
                    sort($oldestInWindow);
                    $waitForThis = ceil($oldestInWindow[0] - $windowStart + 1);
                    $waitNeeded = max($waitNeeded, $waitForThis);

                    $this->apiLogger->warning(
                        "Auth rate limit proche : {$callsInWindow}/{$maxCalls} appels dans les {$windowSeconds}s. Attente de {$waitForThis}s."
                    );
                }
            }

            if ($waitNeeded === 0) {
                // Pas de limite atteinte, enregistrer cet appel et continuer
                $timestamps[] = $now;
                $this->writeAuthTimestamps($timestamps);
                return;
            }

            // Attendre et réessayer
            $sleepTime = min($waitNeeded, $maxWait - $waited);
            if ($sleepTime <= 0) {
                break;
            }

            $this->apiLogger->info("Auth rate limit : attente de {$sleepTime}s avant le prochain appel auth.");
            sleep((int) $sleepTime);
            $waited += $sleepTime;
        }

        throw new Exception("Rate limit auth HelloAsso atteint. Impossible d'effectuer l'appel après {$maxWait}s d'attente.");
    }

    /**
     * Lit les timestamps des appels auth depuis le fichier partagé.
     *
     * @return float[]
     */
    private function readAuthTimestamps(): array
    {
        if (!file_exists($this->authRateLimitFile)) {
            return [];
        }

        $fp = fopen($this->authRateLimitFile, 'r');
        if (!$fp) {
            return [];
        }

        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Écrit les timestamps des appels auth dans le fichier partagé.
     *
     * @param float[] $timestamps
     */
    private function writeAuthTimestamps(array $timestamps): void
    {
        $fp = fopen($this->authRateLimitFile, 'c');
        if (!$fp) {
            $this->apiLogger->warning('Impossible d\'écrire le fichier de rate limit auth.');
            return;
        }

        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode(array_values($timestamps)));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}


