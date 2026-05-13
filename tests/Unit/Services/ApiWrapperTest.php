<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AccessToken;
use App\Repositories\AccessTokenRepository;
use App\Repositories\AuthorizationCodeRepository;
use App\Services\ApiWrapper;
use DateInterval;
use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests de la génération, du rafraîchissement et de la récupération des tokens.
 */
class ApiWrapperTest extends TestCase
{
    private AccessTokenRepository&MockObject $accessTokenRepository;
    private AuthorizationCodeRepository&MockObject $authorizationCodeRepository;
    private Logger $logger;
    private ApiWrapper $apiWrapper;

    protected function setUp(): void
    {
        $this->accessTokenRepository = $this->createMock(AccessTokenRepository::class);
        $this->authorizationCodeRepository = $this->createMock(AuthorizationCodeRepository::class);

        $this->logger = new Logger('test');
        $this->logger->pushHandler(new NullHandler());

        $this->apiWrapper = new ApiWrapper(
            $this->accessTokenRepository,
            $this->authorizationCodeRepository,
            'https://auth.helloasso.com',
            'https://api.helloasso.com',
            'https://api.helloasso.com/oauth2/token',
            'test_client_id',
            'test_client_secret',
            'https://my-widget.test',
            $this->logger
        );
    }

    /**
     * Injecte un client Guzzle mockée dans ApiWrapper via Reflection.
     */
    private function injectMockHttpClient(array $responses): void
    {
        $mockHandler = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        $reflection = new ReflectionClass($this->apiWrapper);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setValue($this->apiWrapper, $mockClient);
    }

    // =====================================================================
    // Génération du token global (client_credentials)
    // =====================================================================

    public function testGetGlobalAccessTokenReturnsExistingValidToken(): void
    {
        $token = new AccessToken();
        $token->access_token = 'existing_valid_token';
        $token->access_token_expires_at = (new DateTime())->add(new DateInterval('PT30M'));
        $token->refresh_token_expires_at = (new DateTime())->add(new DateInterval('P28D'));

        $this->accessTokenRepository
            ->expects($this->once())
            ->method('selectBySlug')
            ->with(null)
            ->willReturn($token);

        $result = $this->apiWrapper->getGlobalAccessToken();

        $this->assertSame($token, $result);
        $this->assertEquals('existing_valid_token', $result->access_token);
    }

    public function testGetGlobalAccessTokenGeneratesNewWhenNoneExists(): void
    {
        $newToken = new AccessToken();
        $newToken->access_token = 'brand_new_token';
        $newToken->refresh_token = 'brand_new_refresh';
        $newToken->access_token_expires_at = (new DateTime())->add(new DateInterval('PT28M'));
        $newToken->refresh_token_expires_at = (new DateTime())->add(new DateInterval('P28D'));

        $this->accessTokenRepository
            ->method('selectBySlug')
            ->with(null)
            ->willReturn(null);

        $this->accessTokenRepository
            ->expects($this->once())
            ->method('insert')
            ->willReturn($newToken);

        $this->injectMockHttpClient([
            new Response(200, [], json_encode([
                'access_token' => 'brand_new_token',
                'refresh_token' => 'brand_new_refresh',
                'expires_in' => 1700,
            ])),
        ]);

        $result = $this->apiWrapper->getGlobalAccessToken();

        $this->assertEquals('brand_new_token', $result->access_token);
        $this->assertEquals('brand_new_refresh', $result->refresh_token);
    }

    public function testGetGlobalAccessTokenRegeneratesWhenExpired(): void
    {
        $expiredToken = new AccessToken();
        $expiredToken->access_token = 'expired_token';
        $expiredToken->access_token_expires_at = (new DateTime())->sub(new DateInterval('PT10M'));
        $expiredToken->refresh_token_expires_at = (new DateTime())->sub(new DateInterval('P1D'));

        $refreshedToken = new AccessToken();
        $refreshedToken->access_token = 'refreshed_token';
        $refreshedToken->refresh_token = 'refreshed_refresh';
        $refreshedToken->access_token_expires_at = (new DateTime())->add(new DateInterval('PT28M'));
        $refreshedToken->refresh_token_expires_at = (new DateTime())->add(new DateInterval('P28D'));

        $this->accessTokenRepository
            ->method('selectBySlug')
            ->willReturn($expiredToken);

        $this->accessTokenRepository
            ->expects($this->once())
            ->method('update')
            ->willReturn($refreshedToken);

        $this->injectMockHttpClient([
            new Response(200, [], json_encode([
                'access_token' => 'refreshed_token',
                'refresh_token' => 'refreshed_refresh',
                'expires_in' => 1700,
            ])),
        ]);

        $result = $this->apiWrapper->getGlobalAccessToken();

        $this->assertEquals('refreshed_token', $result->access_token);
    }

    public function testGetGlobalAccessTokenThrowsOnMissingTokensInResponse(): void
    {
        $this->accessTokenRepository
            ->method('selectBySlug')
            ->willReturn(null);

        $this->injectMockHttpClient([
            new Response(200, [], json_encode(['error' => 'invalid_client'])),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Les tokens ne sont pas présents/');

        $this->apiWrapper->getGlobalAccessToken();
    }

    public function testGetGlobalAccessTokenThrowsOnApiConnectionError(): void
    {
        $this->accessTokenRepository
            ->method('selectBySlug')
            ->willReturn(null);

        $request = new GuzzleRequest('POST', 'https://api.helloasso.com/oauth2/token');
        $this->injectMockHttpClient([
            new RequestException('Connection refused', $request),
        ]);

        $this->expectException(Exception::class);

        $this->apiWrapper->getGlobalAccessToken();
    }

    public function testGetGlobalAccessTokenThrowsOnInvalidJson(): void
    {
        $this->accessTokenRepository
            ->method('selectBySlug')
            ->willReturn(null);

        $this->injectMockHttpClient([
            new Response(200, [], 'not_valid_json{{{'),
        ]);

        $this->expectException(Exception::class);

        $this->apiWrapper->getGlobalAccessToken();
    }

    // =====================================================================
    // Rafraîchissement du token organisation (refresh_token)
    // =====================================================================

    public function testRefreshTokenSuccess(): void
    {
        $updatedToken = new AccessToken();
        $updatedToken->access_token = 'new_access_token';
        $updatedToken->refresh_token = 'new_refresh_token';
        $updatedToken->organization_slug = 'test-org';
        $updatedToken->access_token_expires_at = (new DateTime())->add(new DateInterval('PT28M'));
        $updatedToken->refresh_token_expires_at = (new DateTime())->add(new DateInterval('P28D'));

        // Simule un token existant pour la mise à jour en base
        $existingToken = new AccessToken();
        $existingToken->id = 1;
        $existingToken->organization_slug = 'test-org';

        $this->accessTokenRepository
            ->method('selectBySlug')
            ->with('test-org')
            ->willReturn($existingToken);

        $this->accessTokenRepository
            ->expects($this->once())
            ->method('update')
            ->willReturn($updatedToken);

        $this->injectMockHttpClient([
            new Response(200, [], json_encode([
                'access_token' => 'new_access_token',
                'refresh_token' => 'new_refresh_token',
            ])),
        ]);

        $result = $this->apiWrapper->refreshToken('old_refresh_token', 'test-org');

        $this->assertNotNull($result);
        $this->assertEquals('new_access_token', $result->access_token);
        $this->assertEquals('new_refresh_token', $result->refresh_token);
    }

    public function testRefreshTokenThrowsOnInvalidApiResponse(): void
    {
        $this->injectMockHttpClient([
            new Response(200, [], json_encode(['error' => 'invalid_grant'])),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Les tokens ne sont pas présents/');

        $this->apiWrapper->refreshToken('invalid_refresh', 'test-org');
    }

    public function testRefreshTokenThrowsOnApiError(): void
    {
        $request = new GuzzleRequest('POST', 'https://api.helloasso.com/oauth2/token');
        $this->injectMockHttpClient([
            new RequestException('Unauthorized', $request, new Response(401)),
        ]);

        $this->expectException(Exception::class);

        $this->apiWrapper->refreshToken('expired_token', 'test-org');
    }

    // =====================================================================
    // Récupération du token organisation avec gestion d'expiration
    // =====================================================================

    public function testGetOrganizationAccessTokenReturnsValidToken(): void
    {
        $token = new AccessToken();
        $token->organization_slug = 'my-org';
        $token->access_token = 'valid_org_token';
        $token->refresh_token = 'valid_refresh';
        $token->access_token_expires_at = (new DateTime())->add(new DateInterval('PT20M'));
        $token->refresh_token_expires_at = (new DateTime())->add(new DateInterval('P20D'));

        $this->accessTokenRepository
            ->expects($this->once())
            ->method('selectBySlug')
            ->with('my-org')
            ->willReturn($token);

        $result = $this->apiWrapper->getOrganizationAccessToken('my-org');

        $this->assertEquals('valid_org_token', $result->access_token);
        $this->assertEquals('my-org', $result->organization_slug);
    }

    public function testGetOrganizationAccessTokenRefreshesWhenAccessTokenExpired(): void
    {
        $expiredToken = new AccessToken();
        $expiredToken->organization_slug = 'my-org';
        $expiredToken->access_token = 'expired_access';
        $expiredToken->refresh_token = 'valid_refresh';
        $expiredToken->access_token_expires_at = (new DateTime())->sub(new DateInterval('PT5M'));
        $expiredToken->refresh_token_expires_at = (new DateTime())->add(new DateInterval('P20D'));

        $refreshedToken = new AccessToken();
        $refreshedToken->organization_slug = 'my-org';
        $refreshedToken->access_token = 'new_access_token';
        $refreshedToken->refresh_token = 'new_refresh_token';
        $refreshedToken->access_token_expires_at = (new DateTime())->add(new DateInterval('PT28M'));
        $refreshedToken->refresh_token_expires_at = (new DateTime())->add(new DateInterval('P28D'));

        $this->accessTokenRepository
            ->method('selectBySlug')
            ->with('my-org')
            ->willReturn($expiredToken);

        $this->accessTokenRepository
            ->expects($this->once())
            ->method('update')
            ->willReturn($refreshedToken);

        $this->injectMockHttpClient([
            new Response(200, [], json_encode([
                'access_token' => 'new_access_token',
                'refresh_token' => 'new_refresh_token',
            ])),
        ]);

        $result = $this->apiWrapper->getOrganizationAccessToken('my-org');

        $this->assertEquals('new_access_token', $result->access_token);
    }

    public function testGetOrganizationAccessTokenThrowsWhenRefreshTokenIsExpired(): void
    {
        $tokenWithExpiredRefresh = new AccessToken();
        $tokenWithExpiredRefresh->organization_slug = 'my-org';
        $tokenWithExpiredRefresh->access_token = 'still_valid_access';
        $tokenWithExpiredRefresh->refresh_token = 'expired_refresh';
        $tokenWithExpiredRefresh->access_token_expires_at = (new DateTime())->add(new DateInterval('PT20M'));
        $tokenWithExpiredRefresh->refresh_token_expires_at = (new DateTime())->sub(new DateInterval('P1D'));

        $this->accessTokenRepository
            ->method('selectBySlug')
            ->willReturn($tokenWithExpiredRefresh);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/refresh_token is expired/');

        $this->apiWrapper->getOrganizationAccessToken('my-org');
    }
}


