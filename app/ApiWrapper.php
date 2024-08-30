<?php

class ApiWrapper
{

    private $repository;
    private $haAuthUrl;
    private $apiUrl;
    private $apiAuthUrl;
    private $clientId;
    private $clientSecret;
    private $webSiteDomain;

    public function __construct($repository, $haAuthUrl, $apiUrl, $apiAuthUrl, $clientId, $clientSecret, $webSiteDomain)
    {
        $this->repository = $repository;
        $this->haAuthUrl = $haAuthUrl;
        $this->apiUrl = $apiUrl;
        $this->apiAuthUrl = $apiAuthUrl;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->webSiteDomain = $webSiteDomain;
    }

    // Authentification

    function getAccessTokensAndRefreshIfNecessary($organization_slug)
    {
        $tokenData = $this->repository->getAccessTokensDB($organization_slug);

        if ($tokenData == null) {
            if ($organization_slug == null) {
                $tokenData = $this->generateGlobalAccessToken();
                return $tokenData;
            } else {
                return null;
            }
        } else {
            $tokenData['access_token'] = Helpers::decryptToken($tokenData['access_token']);
            $tokenData['refresh_token'] = Helpers::decryptToken($tokenData['refresh_token']);
            if ($tokenData['access_token_expires_at'] < date('Y-m-d H:i:s')) {
                $tokenData = $this->refreshToken($tokenData['refresh_token'], null);
                return $tokenData;
            }
            return $tokenData;
        }
    }

    function generateGlobalAccessToken()
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->apiAuthUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret
            ]),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/x-www-form-urlencoded'
            ),
        ));

        // Exécuter la requête
        $response = curl_exec($curl);

        // Gérer les erreurs cURL
        if (curl_errno($curl)) {
            $error_msg = curl_error($curl);
            curl_close($curl);
            return 'Erreur cURL : ' . $error_msg;
        }

        // Fermer cURL
        curl_close($curl);

        // Décoder la réponse JSON
        $responseData = json_decode($response, true);

        // Vérifier si la réponse a bien été décodée
        if (json_last_error() !== JSON_ERROR_NONE) {
            return 'Erreur de décodage JSON : ' . json_last_error_msg();
        }

        // Vérifier que les tokens sont présents dans la réponse
        if (!isset($responseData['access_token']) || !isset($responseData['refresh_token'])) {
            return 'Erreur : Les tokens ne sont pas présents dans la réponse.';
        }

        $accessTokenExpiresAt = (new DateTime())->add(new DateInterval('PT1700S'));
        $refreshTokenExpiresAt = (new DateTime())->add(new DateInterval('P29D'));

        // Insérer les tokens en base de données
        $this->repository->insertAccessTokenDB(
            Helpers::encryptToken($responseData['access_token']),
            Helpers::encryptToken($responseData['refresh_token']),
            null,
            $accessTokenExpiresAt,
            $refreshTokenExpiresAt
        );

        return $responseData;
    }

    function refreshToken($refreshToken, $organization_slug)
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->apiAuthUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => 'grant_type=refresh_token&refresh_token=' . $refreshToken,
            CURLOPT_HTTPHEADER => array(
                'Cache-Control: no-cache',
                'content-type: application/x-www-form-urlencoded',
            ),
        ));

        $response = curl_exec($curl);

        // Décoder la réponse JSON
        $responseData = json_decode($response, true);

        // Vérifier si la réponse a bien été décodée
        if (json_last_error() !== JSON_ERROR_NONE) {
            return 'Erreur de décodage JSON : ' . json_last_error_msg();
        }

        curl_close($curl);

        // Calculer les dates d'expiration des tokens
        $accessTokenExpiresAt = (new DateTime())->add(new DateInterval('PT28M'));
        $refreshTokenExpiresAt = (new DateTime())->add(new DateInterval('P28D'));

        $this->repository->UpdateAccessTokenDB(
            Helpers::encryptToken($responseData['access_token']),
            Helpers::encryptToken($responseData['refresh_token']),
            $organization_slug,
            $accessTokenExpiresAt,
            $refreshTokenExpiresAt
        );

        return $responseData;
    }

    // Grant authorisation

    function generateAuthorizationUrl($organization_slug)
    {
        $uniqueUUID = Helpers::generateUUID();
        $codeVerifier = Helpers::generateRandomString();
        $redirectUri = $this->webSiteDomain . '/validate_grant_authorization.php';

        $this->repository->insertAuthorizationCodeDB($uniqueUUID, $codeVerifier, $redirectUri, $organization_slug);

        // Générer le code challenge
        $codeChallenge = Helpers::generatePKCEChallenge($codeVerifier);

        // Construire l'URL finale
        $authorizationUrl = $this->haAuthUrl . "/authorize?" . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => $uniqueUUID
        ]);

        return $authorizationUrl;
    }

    function setClientDomain($domain, $accessToken)
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->apiUrl . '/partners/me/api-clients',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode([
                'Domain' => $domain
            ]),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken
            ),
        ));

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code !== 200) {
            die("Erreur Set Domain : L'appel API a échoué avec le code HTTP " . $http_code);
        }
    }

    function exchangeAuthorizationCode($code, $redirect_uri, $codeVerifier)
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->apiAuthUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'authorization_code',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $code,
                'redirect_uri' => $redirect_uri,
                'code_verifier' => $codeVerifier
            ]),
            CURLOPT_HTTPHEADER => array(
                'cache-control: no-cache',
                'content-type: application/x-www-form-urlencoded',
            ),
        ));

        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code !== 200) {
            die("Erreur : L'appel API a échoué avec le code HTTP " . $http_code);
        }

        // Décoder la réponse JSON
        $responseData = json_decode($response, true);

        // Vérifier si la réponse a bien été décodée
        if (json_last_error() !== JSON_ERROR_NONE) {
            return 'Erreur de décodage JSON : ' . json_last_error_msg();
        }

        return $responseData;
    }

    // Organizations

    function GetDonationForm($organizationSlug, $donationSlug)
    {
        $accessToken = $this->getAccessTokensAndRefreshIfNecessary(null);
        if (!$accessToken || !isset($accessToken['access_token'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Jeton d\'accès API non trouvé ou expiré.']);
            exit;
        }

        $curl = curl_init();

        // Construire l'URL avec ou sans continuationToken
        $url = $this->apiUrl . '/organizations/' . $organizationSlug . '/forms/donation/' . $donationSlug . '/public';

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
                'Authorization: Bearer ' . $accessToken['access_token']
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);

        $response_data = json_decode($response, true);
        return $response_data;
    }

    function GetDonationFormOrders($organizationSlug, $donationSlug, $accessToken, $continuationToken = null, $from = null)
    {
        $curl = curl_init();

        // Construire l'URL avec ou sans continuationToken
        $url = $this->apiUrl . '/organizations/' . $organizationSlug . '/forms/donation/' . $donationSlug . '/orders?withDetails=true&sortOrder=asc';
        if ($continuationToken) {
            $url .= '&continuationToken=' . $continuationToken;
        }
        if ($from) {
            $url .= '&from=' . $from;
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

    function GetAllOrders($organizationSlug, $formSlug, $currentAmount = 0, $continuationToken = null, $from = null) {
        $previousToken = '';
        $donations = [];

        $organizationAccessToken = $this->getAccessTokensAndRefreshIfNecessary($organizationSlug);
        if (!$organizationAccessToken || !isset($organizationAccessToken['access_token'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Jeton d\'accès API non trouvé ou expiré.']);
            exit;
        }
        
        do {
            // Appel de l'API pour récupérer les ordres de donations avec le continuationToken s'il existe
            $formOrdersData = $this->getDonationFormOrders(
                $organizationSlug,
                $formSlug,
                $organizationAccessToken['access_token'],
                $continuationToken,
                $from
            );
        
            if (!isset($formOrdersData['data'])) {
                break; // no new data
            }
        
            // Incrémentation du montant total avec les montants récupérés dans cette page
            foreach ($formOrdersData['data'] as $order) {
        
                $pseudo = "anonyme";
                $message = "";
        
                foreach ($order['items'] as $item) {
                    foreach ($item['customFields'] as $field) {
                        if ($field['name'] == 'pseudo') {
                            $pseudo = $field['answer'];
                        }
                        if ($field['name'] == 'message') {
                            $message = $field['answer'];
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
        
            // Mise à jour du continuationToken pour récupérer la page suivante
            $previousToken = $continuationToken;
            $continuationToken = $formOrdersData['pagination']['continuationToken'] ?? null;
        
            // Tant que le token actuel est différent de l'ancien, on continue la boucle
        } while ($continuationToken && $continuationToken !== $previousToken);
        
        return [
            'amount' => $currentAmount,
            'donations' => $donations,
            'continuationToken' => $continuationToken
        ];
    }
}