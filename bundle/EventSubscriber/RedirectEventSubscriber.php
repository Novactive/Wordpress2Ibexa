<?php

declare(strict_types=1);

namespace Almaviacx\Bundle\Ibexa\WordPress\EventSubscriber;

use Almaviacx\Bundle\Ibexa\WordPress\Service\RedirectService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

class RedirectEventSubscriber implements EventSubscriberInterface
{

    private RedirectService $redirectService;
    private RouterInterface $router;

    public function __construct(RedirectService $redirectService, RouterInterface $router)
    {
        $this->redirectService = $redirectService;
        $this->router = $router;
    }

    /**
     * @inheritDoc
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => [['onKernelException', 10],],
        ];
    }

    public function onKernelException(ExceptionEvent $event)
    {
        $exception = $event->getThrowable();
        if($exception instanceof NotFoundHttpException && $exception->getStatusCode() === Response::HTTP_NOT_FOUND) {
            $request = $event->getRequest();
            $semanticPathInfo = $request->attributes->get('semanticPathinfo', null);
            if ($semanticPathInfo) {
                $locationId = $this->redirectService->getIbexaLocationId($semanticPathInfo);
                if ($locationId !== null) {
                    $event->setResponse(
                        new RedirectResponse(
                            $this->router->generate('ibexa.url.alias', ['locationId' => $locationId])
                        )
                    );
                }
            }
        }
    }
}