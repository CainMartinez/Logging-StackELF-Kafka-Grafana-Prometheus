<?php

namespace App\VozFusion\Metrics\Controller;

use App\VozFusion\Metrics\Service\MetricsService;
use Prometheus\RenderTextFormat;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MetricsController
{
    private MetricsService $metricsService;

    public function __construct(MetricsService $metricsService)
    {
        $this->metricsService = $metricsService;
    }

    public function __invoke(Request $request): Response
    {
        // Registramos el uso de memoria justo antes de devolver las métricas
        $this->metricsService->registerSpringBootLikeMetrics();

        $this->metricsService->recordMemoryUsage();
        
        $registry = $this->metricsService->getRegistry();
        
        // Determinar el formato basado en el encabezado Accept o parámetro format
        $format = $request->query->get('format', 'prometheus');
        $acceptHeader = AcceptHeader::fromString($request->headers->get('Accept', ''));

        $this->metricsService->incrementRequestCounter(
            $request->getMethod(),
            $request->getPathInfo(),
            '200'
        );
        
        if ($acceptHeader->has('application/json') && $format !== 'prometheus') {
            $format = 'json';
        }
        
        // Para el formato JSON, devolvemos datos tipo Actuator junto con métricas
        if ($format === 'json') {
            $actuatorData = $this->metricsService->getActuatorData();
            
            // Incluir endpoint específico si se solicita
            if ($request->query->has('endpoint')) {
                $endpoint = $request->query->get('endpoint');
                if (isset($actuatorData[$endpoint])) {
                    return new JsonResponse($actuatorData[$endpoint]);
                }
                return new JsonResponse(['error' => 'Endpoint not found'], 404);
            }
            
            return new JsonResponse($actuatorData);
        }
        
        // Formato por defecto: Prometheus
        $renderer = new RenderTextFormat();
        $output = $renderer->render($registry->getMetricFamilySamples());
        
        return new Response(
            $output, 
            200, 
            ['Content-Type' => RenderTextFormat::MIME_TYPE]
        );
    }
}