<?php

namespace App\VozFusion\Metrics\EventSubscriber;

use App\VozFusion\Metrics\Service\MetricsService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class MetricsSubscriber implements EventSubscriberInterface
{
    private MetricsService $metricsService;
    private float $requestStartTime;
    private LoggerInterface $logger;

    public function __construct(MetricsService $metricsService, ?LoggerInterface $logger = null)
    {
        $this->requestStartTime = 0.0;
        $this->metricsService = $metricsService;
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 9999], // Alta prioridad para capturar al inicio
            KernelEvents::RESPONSE => ['onKernelResponse', -9999], // Baja prioridad para capturar al final
            KernelEvents::TERMINATE => ['onKernelTerminate', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
    
        $request = $event->getRequest();
        
        file_put_contents('/var/www/Symfony-7.2/var/log/debug.log', 
            date('Y-m-d H:i:s') . ' - Request received: ' . $request->getPathInfo() . PHP_EOL, 
            FILE_APPEND);
        
        $this->logger->info('Request received', [
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
            'client_ip' => $request->getClientIp(),
            'query_params' => $request->query->all()
        ]);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        
        $request = $event->getRequest();
        $response = $event->getResponse();
        
        // Calculamos el tiempo transcurrido
        $responseTime = microtime(true) - $this->requestStartTime;
        
        // Registramos el tiempo de respuesta
        $this->metricsService->observeResponseTime(
            $responseTime,
            $request->getMethod(),
            $request->getPathInfo()
        );
        
        // Incrementamos el contador con información sobre el código de estado
        $this->metricsService->incrementRequestCounter(
            $request->getMethod(),
            $request->getPathInfo(),
            (string)$response->getStatusCode()
        );
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        // Registramos el uso de memoria al final de la petición
        $this->metricsService->recordMemoryUsage();
    }
}