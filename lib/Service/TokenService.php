<?php

namespace OCA\OIDCLogin\Service;

use Exception;
use OCA\OIDCLogin\Provider\OpenIDConnectClient;
use OCP\IConfig;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\ILogger;

class TokenService
{
    /** @var string */
    private $appName;

    /** @var ISession */
    private $session;

    /** @var IConfig */
    private $config;

    /** @var IURLGenerator */
    private $urlGenerator;

    /** @var ILogger */
    private ILogger $logger;


    public function __construct(
        $appName,
        ISession $session,
        IConfig $config,
        IURLGenerator $urlGenerator,
        ILogger $logger
    ) {
        $this->appName = $appName;
        $this->session = $session;
        $this->config = $config;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
    }

    /**
     * @param string $callbackUrl
     *
     * @return OpenIDConnectClient Configured instance of OpendIDConnectClient
     */
    public function createOIDCClient($callbackUrl = '')
    {
        $oidc = new OpenIDConnectClient(
            $this->session,
            $this->config,
            $this->appName,
        );
        $oidc->setRedirectURL($callbackUrl);

        // set TLS development mode
        $oidc->setVerifyHost($this->config->getSystemValue('oidc_login_tls_verify', true));
        $oidc->setVerifyPeer($this->config->getSystemValue('oidc_login_tls_verify', true));

        // Set OpenID Connect Scope
        $scope = $this->config->getSystemValue('oidc_login_scope', 'openid');
        $oidc->addScope($scope);

        return $oidc;
    }

    /**
     * @return bool Whether or not valid access token
     */
    public function refreshTokens(): bool
    {
        $accessTokenExpiresAt = $this->session->get('oidc_access_token_expires_at');
        $now = time();
        // If access token hasn't expired yet
        $this->logger->debug("checking if token should be refreshed", ["expires" => $accessTokenExpiresAt]);

        if (!empty($accessTokenExpiresAt) && $now < $accessTokenExpiresAt) {
            $this->logger->debug("no token expiration or not yet expired");
            return true;
        }

        $refreshToken = $this->session->get('oidc_refresh_token');
        // If refresh token doesn't exist or refresh token has expired
        if (empty($refreshToken)) {
            $this->logger->debug("refresh token not found");
            return false;
        }

        $callbackUrl = $this->urlGenerator->linkToRouteAbsolute($this->appName.'.login.oidc');

        // Refresh the tokens, return false on failure
        $this->logger->debug("refreshing token");
        try {
            $oidc = $this->createOIDCClient($callbackUrl);
            $tokenResponse = $oidc->refreshToken($refreshToken);
            $this->storeTokens($tokenResponse);

            if ($this->session->get('oidc_logout_url')) {
                $this->logger->debug("updating logout url");
                $oidc_login_logout_url = $this->config->getSystemValue('oidc_login_logout_url', false);
                $logoutUrl = $oidc->getEndSessionUrl($oidc_login_logout_url);
                $this->session->set('oidc_logout_url', $logoutUrl);
            }

            $this->logger->debug("token refreshed");
            return true;
        } catch (Exception $e) {
            $this->logger->error("token refresh failed", ['exception' => $e]);
            return false;
        }
    }

    public function storeTokens(object $tokenResponse): void
    {
        $oldAccessToken = $this->session->get('oidc_access_token');
        $this->logger->debug("old access token: " . $oldAccessToken);
        $this->logger->debug("new access token: " . $tokenResponse->access_token);

        $this->session->set('oidc_access_token', $tokenResponse->access_token);
        $this->session->set('oidc_refresh_token', $tokenResponse->refresh_token);

        $now = time();
        $accessTokenExpiresAt = $tokenResponse->expires_in + $now;

        $this->session->set('oidc_access_token_expires_at', $accessTokenExpiresIn);
    }

    public function getLogoutUrl() {
        
        return $this->session->get('oidc_logout_url');
    }
}
