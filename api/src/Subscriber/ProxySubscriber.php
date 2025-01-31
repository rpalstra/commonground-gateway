<?php

namespace App\Subscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use App\Entity\Gateway as Source;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\FileSystemHandleService;
use CommonGateway\CoreBundle\Service\RequestService;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use Safe\Exceptions\UrlException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\SerializerInterface;

class ProxySubscriber implements EventSubscriberInterface
{
    /**
     * @var EntityManagerInterface The entity manager
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var CallService The call service
     */
    private CallService $callService;

    /**
     * @var FileSystemHandleService The fileSystem service
     */
    private FileSystemHandleService $fileSystemService;

    /**
     * @var RequestService The request service
     */
    private RequestService $requestService;

    /**
     * @var SerializerInterface The serializer
     */
    private SerializerInterface $serializer;

    public const PROXY_ROUTES = [
        'api_gateways_get_proxy_item',
        'api_gateways_get_proxy_endpoint_item',
        'api_gateways_post_proxy_collection',
        'api_gateways_post_proxy_endpoint_collection',
        'api_gateways_put_proxy_single_item',
        'api_gateways_delete_proxy_single_item',
    ];

    /**
     * The constructor of this subscriber class.
     *
     * @param EntityManagerInterface  $entityManager     The entity manager
     * @param CallService             $callService       The call service
     * @param FileSystemHandleService $fileSystemService The fileSystem service
     * @param RequestService          $requestService    The request service
     * @param SerializerInterface     $serializer        The serializer
     */
    public function __construct(EntityManagerInterface $entityManager, CallService $callService, FileSystemHandleService $fileSystemService, RequestService $requestService, SerializerInterface $serializer)
    {
        $this->entityManager = $entityManager;
        $this->callService = $callService;
        $this->fileSystemService = $fileSystemService;
        $this->requestService = $requestService;
        $this->serializer = $serializer;
    }

    /**
     * Get Subscribed Events.
     *
     * @return array[] Subscribed Events
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => ['proxy', EventPriorities::PRE_DESERIALIZE],
        ];
    }

    /**
     * Handle Proxy.
     *
     * @param RequestEvent $event The Event
     *
     * @throws GuzzleException|UrlException
     *
     * @return void
     */
    public function proxy(RequestEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');

        if (!in_array($route, self::PROXY_ROUTES)) {
            return;
        }

        //@Todo rename
        $source = $this->entityManager->getRepository('App:Gateway')->find($event->getRequest()->attributes->get('id'));
        if (!$source instanceof Source) {
            return;
        }

        $headers = array_merge_recursive($source->getHeaders(), $event->getRequest()->headers->all());
        $endpoint = $headers['x-endpoint'][0] ?? '';
        if (empty($endpoint) === false && str_starts_with($endpoint, '/') === false && str_ends_with($source->getLocation(), '/') === false) {
            $endpoint = '/'.$endpoint;
        }
        $endpoint = rtrim($endpoint, '/');

        $method = $headers['x-method'][0] ?? $event->getRequest()->getMethod();
        unset($headers['authorization']);
        unset($headers['x-endpoint']);
        unset($headers['x-method']);

        $url = \Safe\parse_url($source->getLocation());

        try {
            if ($url['scheme'] === 'http' || $url['scheme'] === 'https') {
                $result = $this->callService->call(
                    $source,
                    $endpoint,
                    $method,
                    [
                        'headers' => $headers,
                        'query'   => $this->requestService->realRequestQueryAll($event->getRequest()->getQueryString()),
                        'body'    => $event->getRequest()->getContent(),
                    ]
                );
            } else {
                $result = $this->fileSystemService->call($source, $endpoint);
                $result = new \GuzzleHttp\Psr7\Response(200, [], $this->serializer->serialize($result, 'json'));
            }
        } catch (ServerException|ClientException|RequestException $exception) {
            $statusCode = $exception->getCode() ?? 500;
            if (method_exists(get_class($exception), 'getResponse') === true && $exception->getResponse() !== null) {
                $body = $exception->getResponse()->getBody()->getContents();
                $statusCode = $exception->getResponse()->getStatusCode();
                $headers = $exception->getResponse()->getHeaders();
            }
            $content = $this->serializer->serialize(
                [
                    'Message' => $exception->getMessage(),
                    'Body'    => $body ?? "Can\'t get a response & body for this type of Exception: ".get_class($exception),
                ],
                'json'
            );

            $result = new \GuzzleHttp\Psr7\Response($statusCode, $headers, $content);

            // If error catched dont pass event->getHeaders (causes infinite loop)
            $wentWrong = true;
        }
        $event->setResponse(new Response($result->getBody()->getContents(), $result->getStatusCode(), !isset($wentWrong) ? $result->getHeaders() : []));
    }
}
