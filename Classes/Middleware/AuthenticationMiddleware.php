<?php

declare(strict_types=1);

namespace FSG\Oidc\Middleware;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use FSG\Oidc\LoginProvider\OpenIDConnectSignInProvider;
use FSG\Oidc\Traits\AuthenticationTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Authentication\LoginType;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Context\Context;

/**
 * Class AuthenticationMiddleware
 */
class AuthenticationMiddleware implements MiddlewareInterface, LoggerAwareInterface
{
    use AuthenticationTrait;
    use LoggerAwareTrait;

    const PATH         = '/oidc/authentication';
    const BACKEND_URI  = '%s/typo3/?loginProvider=%d';
    const FRONTEND_URI = '%s://%s%s%slogintype=%s';

    /**
     * @param ServerRequestInterface  $request
     * @param RequestHandlerInterface $handler
     *
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (parse_url($request->getServerParams()['HTTP_REFERER'] ?? '', PHP_URL_HOST)
            !== parse_url(GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST'), PHP_URL_HOST)
            || strpos($request->getUri()->getPath(), self::PATH) === false) {
            return $handler->handle($request);
        }

        return $this->handle($request);
    }

    /**
     * @param ServerRequestInterface $request
     * @param bool                   $responsible
     *
     * @return ?RedirectResponse
     */
    protected function handle(ServerRequestInterface $request, bool $responsible = true): ?ResponseInterface
    {
        $this->logger->debug('Handle login.');
        $this->initReferrer($request, LoginType::LOGIN);
        return new RedirectResponse($this->authenticateUser(), 302);
    }
}
