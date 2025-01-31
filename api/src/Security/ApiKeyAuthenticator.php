<?php

namespace App\Security;

use App\Entity\User;
use App\Service\FunctionService;
use Conduction\CommonGroundBundle\Service\AuthenticationService;
use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Conduction\SamlBundle\Security\User\AuthenticationUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\PassportInterface;

class ApiKeyAuthenticator extends \Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator
{
    private CommonGroundService $commonGroundService;
    private ParameterBagInterface $parameterBag;
    private AuthenticationService $authenticationService;
    private SessionInterface $session;
    private FunctionService $functionService;
    private EntityManagerInterface $entityManager;

    public function __construct(
        CommonGroundService $commonGroundService,
        AuthenticationService $authenticationService,
        ParameterBagInterface $parameterBag,
        SessionInterface $session,
        FunctionService $functionService,
        EntityManagerInterface $entityManager
    ) {
        $this->commonGroundService = $commonGroundService;
        $this->parameterBag = $parameterBag;
        $this->authenticationService = $authenticationService;
        $this->session = $session;
        $this->functionService = $functionService;
        $this->entityManager = $entityManager;
    }

    /**
     * @inheritDoc
     */
    public function supports(Request $request): ?bool
    {
        return $request->headers->has('Authorization') &&
            strpos($request->headers->get('Authorization'), 'Bearer') === false;
    }

    /**
     * Get all the child organisations for an organisation.
     *
     * @param array               $organizations
     * @param string              $organization
     * @param CommonGroundService $commonGroundService
     * @param FunctionService     $functionService
     *
     * @throws \Psr\Cache\CacheException
     * @throws \Psr\Cache\InvalidArgumentException
     *
     * @return array
     */
    private function getSubOrganizations(array $organizations, string $organization, CommonGroundService $commonGroundService, FunctionService $functionService): array
    {
        if ($organization = $functionService->getOrganizationFromCache($organization)) {
            if (!empty($organization['subOrganizations']) && count($organization['subOrganizations']) > 0) {
                foreach ($organization['subOrganizations'] as $subOrganization) {
                    if (!in_array($subOrganization['@id'], $organizations)) {
                        $organizations[] = $subOrganization['@id'];
                        $this->getSubOrganizations($organizations, $subOrganization['@id'], $commonGroundService, $functionService);
                    }
                }
            }
        }

        return $organizations;
    }

    /**
     * Get al the parent organizations for an organisation.
     *
     * @param array               $organizations
     * @param string              $organization
     * @param CommonGroundService $commonGroundService
     * @param FunctionService     $functionService
     *
     * @throws \Psr\Cache\CacheException
     * @throws \Psr\Cache\InvalidArgumentException
     *
     * @return array
     */
    private function getParentOrganizations(array $organizations, string $organization, CommonGroundService $commonGroundService, FunctionService $functionService): array
    {
        if ($organization = $functionService->getOrganizationFromCache($organization)) {
            if (array_key_exists('parentOrganization', $organization) && $organization['parentOrganization'] != null
                && !in_array($organization['parentOrganization']['@id'], $organizations)) {
                $organizations[] = $organization['parentOrganization']['@id'];
                $organizations = $this->getParentOrganizations($organizations, $organization['parentOrganization']['@id'], $commonGroundService, $functionService);
            }
        }

        return $organizations;
    }

    private function prefixRoles(array $roles): array
    {
        foreach ($roles as $key => $value) {
            $roles[$key] = "ROLE_$value";
        }

        return $roles;
    }

    private function getActiveOrganization(array $user, array $organizations): ?string
    {
        if (isset($user['organization'])) {
            return $user['organization'];
        }
        // If user has no organization, we default activeOrganization to an organization of a userGroup this user has
        if (count($organizations) > 0) {
            return $organizations[0];
        }
        // If we still have no organization, get the organization from the application
        if ($this->session->get('application')) {
            $application = $this->entityManager->getRepository('App:Application')->findOneBy(['id' => $this->session->get('application')]);
            if (!empty($application) && $application->getOrganization()) {
                return $application->getOrganization();
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function authenticate(Request $request): PassportInterface
    {
        $key = $request->headers->get('Authorization');
        $application = $this->entityManager->getRepository('App:Application')->findOneBy(['secret' => $key]);
        if (!$application) {
            throw new AuthenticationException('Invalid ApiKey');
        }

        try {
            $user = $application->getOrganization()->getUsers()[0];
        } catch (\Exception $exception) {
            throw new AuthenticationException('Invalid User');
        }
        $this->session->set('apiKeyApplication', $application->getId()->toString());

        if (!$user || !($user instanceof User)) {
            throw new AuthenticationException('The provided token does not match the user it refers to');
        }
        $roleArray = [];
        foreach ($user->getSecurityGroups() as $securityGroup) {
            $roleArray['roles'][] = "Role_{$securityGroup->getName()}";
            $roleArray['roles'] = array_merge($roleArray['roles'], $securityGroup->getScopes());
        }

        if (!in_array('ROLE_USER', $roleArray['roles'])) {
            $roleArray['roles'][] = 'ROLE_USER';
        }
        foreach ($roleArray['roles'] as $key => $role) {
            if (strpos($role, 'ROLE_') !== 0) {
                $roleArray['roles'][$key] = "ROLE_$role";
            }
        }

        $organizations = [];
        if ($user->getOrganisation()) {
            $organizations[] = $user->getOrganisation();
        }

        $organizations[] = 'localhostOrganization';
        $this->session->set('organizations', $organizations);
        // If user has no organization, we default activeOrganization to an organization of a userGroup this user has and else the application organization;
        $this->session->set('activeOrganization', $user->getOrganisation());

        $userArray = [
            'id'           => $user->getId()->toString(),
            'email'        => $user->getEmail(),
            'locale'       => $user->getLocale(),
            'organization' => $user->getOrganisation()->getId()->toString(),
            'roles'        => $roleArray['roles'],
        ];

        return new Passport(
            new UserBadge($userArray['id'], function ($userIdentifier) use ($userArray) {
                return new AuthenticationUser(
                    $userIdentifier,
                    $userArray['email'],
                    '',
                    '',
                    '',
                    $userArray['email'],
                    '',
                    $userArray['roles'],
                    $userArray['email'],
                    $userArray['locale'],
                    $userArray['organization'],
                    null
                );
            }),
            new CustomCredentials(
                function (array $credentials, UserInterface $user) {
                    return $user->getUserIdentifier() == $credentials['id'];
                },
                $userArray
            )
        );
    }

    /**
     * @inheritDoc
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $data = [
            'message'   => strtr($exception->getMessageKey(), $exception->getMessageData()),
            'exception' => $exception->getMessage(),

            // or to translate this message
            // $this->translator->trans($exception->getMessageKey(), $exception->getMessageData())
        ];

        return new JsonResponse($data, Response::HTTP_UNAUTHORIZED);
    }
}
