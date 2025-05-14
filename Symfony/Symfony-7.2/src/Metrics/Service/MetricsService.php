<?php

namespace App\VozFusion\Metrics\Service;

use Doctrine\DBAL\Connection;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\APC;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Kernel;
use Redis;

class MetricsService
{
    private CollectorRegistry $registry;
    private ?ContainerInterface $container;
    private ?Connection $connection;
    private ?Redis $redis;

    public function __construct(
        ?ContainerInterface $container = null,
        ?Connection $connection = null,
        ?Redis $redis = null
    ) {
        // Inicializamos el adaptador para métricas de Prometheus
        $adapter = new APC();
        $this->registry = new CollectorRegistry($adapter);
        
        $this->container = $container;
        $this->connection = $connection;
        $this->redis = $redis;
    }

    public function getRegistry(): CollectorRegistry
    {
        return $this->registry;
    }

    public function incrementRequestCounter(string $method = 'GET', string $endpoint = 'unknown', string $status = 'unknown'): void
    {
        $counter = $this->registry->getOrRegisterCounter(
            'app',
            'http_requests_total',
            'Total de peticiones HTTP procesadas',
            ['method', 'endpoint', 'status']
        );
        $counter->inc([$method, $endpoint, $status]);
    }

    public function observeResponseTime(float $time, string $method, string $endpoint): void
    {
        $histogram = $this->registry->getOrRegisterHistogram(
            'app',
            'http_request_duration_seconds',
            'Tiempo de respuesta HTTP en segundos',
            ['method', 'endpoint'],
            [0.01, 0.05, 0.1, 0.5, 1, 2, 5]
        );
        $histogram->observe($time, [$method, $endpoint]);
    }
    
    public function recordMemoryUsage(): void
    {
        $gauge = $this->registry->getOrRegisterGauge(
            'app',
            'memory_usage_bytes',
            'Uso de memoria en bytes',
            []
        );
        $gauge->set(memory_get_usage(true));
    }
    
    // Nuevos métodos para Actuator
    
    /**
     * Obtiene información completa del sistema para el endpoint Actuator
     */
    public function getActuatorData(): array
    {
        return [
            'status' => $this->getHealthStatus(),
            'application' => $this->getApplicationInfo(),
            'system' => $this->getSystemInfo(),
            'database' => $this->getDatabaseInfo(),
            'cache' => $this->getCacheInfo(),
            'memory' => $this->getMemoryInfo(),
            'http' => $this->getHttpInfo()
        ];
    }
    
    /**
     * Obtiene el estado de salud general del sistema
     */
    private function getHealthStatus(): array
    {
        $status = "UP";
        $components = [];
        
        // Comprobar base de datos
        if ($this->connection) {
            try {
                $this->connection->fetchOne('SELECT 1');
                $components['database'] = ['status' => 'UP'];
            } catch (\Exception $e) {
                $status = "DOWN";
                $components['database'] = [
                    'status' => 'DOWN',
                    'error' => $e->getMessage()
                ];
            }
        } else {
            $components['database'] = ['status' => 'UNKNOWN'];
        }
        
        // Comprobar Redis
        if ($this->redis) {
            try {
                $this->redis->ping();
                $components['redis'] = ['status' => 'UP'];
            } catch (\Exception $e) {
                $status = "DOWN";
                $components['redis'] = [
                    'status' => 'DOWN',
                    'error' => $e->getMessage()
                ];
            }
        } else {
            $components['redis'] = ['status' => 'UNKNOWN'];
        }
        
        // Comprobar espacio en disco
        $diskFree = @disk_free_space('/');
        $diskTotal = @disk_total_space('/');
        
        if ($diskFree !== false && $diskTotal !== false) {
            $diskStatus = ($diskFree / $diskTotal) > 0.1 ? 'UP' : 'DOWN';
            if ($diskStatus === 'DOWN') {
                $status = 'DOWN';
            }
            
            $components['disk'] = [
                'status' => $diskStatus,
                'free' => $this->formatBytes($diskFree),
                'total' => $this->formatBytes($diskTotal),
            ];
        }
        
        return [
            'status' => $status,
            'components' => $components,
        ];
    }
    
    /**
     * Obtiene información de la aplicación
     */
    private function getApplicationInfo(): array
    {
        return [
            'name' => 'VozFusion',
            'description' => 'VozFusion Symfony Application',
            'version' => $_ENV['APP_VERSION'] ?? '1.0.0',
            'environment' => $_ENV['APP_ENV'] ?? 'prod',
            'symfony' => Kernel::VERSION,
            'php_version' => PHP_VERSION,
        ];
    }
    
    /**
     * Obtiene información del sistema
     */
    private function getSystemInfo(): array
    {
        $uptime = null;
        if (function_exists('shell_exec')) {
            $uptime = shell_exec('uptime -p 2>/dev/null');
            if ($uptime) {
                $uptime = trim($uptime);
            }
        }
        
        return [
            'hostname' => gethostname(),
            'os' => php_uname(),
            'uptime' => $uptime ?? 'unknown',
            'load_average' => function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0],
            'processors' => $this->getProcessorCount(),
        ];
    }
    
    /**
     * Obtiene información de la base de datos
     */
    private function getDatabaseInfo(): array
    {
        if (!$this->connection) {
            return ['status' => 'not configured'];
        }
        
        try {
            $info = $this->connection->fetchAssociative('SELECT version() as version, database() as name');
            $stats = [
                'status' => 'connected',
                'driver' => get_class($this->connection->getDriver()),
                'version' => $info['version'] ?? 'unknown',
                'name' => $info['name'] ?? 'unknown',
            ];
            
            return $stats;
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtiene información de caché de Redis
     */
    private function getCacheInfo(): array
    {
        if (!$this->redis) {
            return ['status' => 'not configured'];
        }
        
        try {
            $info = $this->redis->info();
            return [
                'status' => 'connected',
                'version' => $info['redis_version'] ?? 'unknown',
                'uptime_days' => $info['uptime_in_days'] ?? 0,
                'memory_used' => $this->formatBytes(($info['used_memory'] ?? 0)),
                'peak_memory' => $this->formatBytes(($info['used_memory_peak'] ?? 0)),
                'connected_clients' => $info['connected_clients'] ?? 0,
                'evicted_keys' => $info['evicted_keys'] ?? 0,
                'hits' => $info['keyspace_hits'] ?? 0,
                'misses' => $info['keyspace_misses'] ?? 0,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtiene información de memoria de PHP
     */
    private function getMemoryInfo(): array
    {
        $memLimit = $this->getPhpMemoryLimit();
        
        return [
            'current_usage' => $this->formatBytes(memory_get_usage()),
            'current_usage_real' => $this->formatBytes(memory_get_usage(true)),
            'peak_usage' => $this->formatBytes(memory_get_peak_usage()),
            'peak_usage_real' => $this->formatBytes(memory_get_peak_usage(true)),
            'php_memory_limit' => $memLimit ? $this->formatBytes($memLimit) : 'unknown'
        ];
    }
    
    /**
     * Obtiene información de solicitudes HTTP
     */
    private function getHttpInfo(): array
    {
        $httpMetrics = [];
        
        foreach ($this->registry->getMetricFamilySamples() as $metricFamily) {
            if (strpos($metricFamily->getName(), 'http_') === 0) {
                $httpMetrics[$metricFamily->getName()] = [
                    'help' => $metricFamily->getHelp(),
                    'type' => $metricFamily->getType(),
                    'samples' => []
                ];
                
                foreach ($metricFamily->getSamples() as $sample) {
                    $httpMetrics[$metricFamily->getName()]['samples'][] = [
                        'labels' => $sample->getLabelValues(),
                        'value' => $sample->getValue()
                    ];
                }
            }
        }
        
        return $httpMetrics;
    }
    
    /**
     * Formatea bytes a una representación legible
     */
    private function formatBytes($bytes, $precision = 2): string
    {
        if ($bytes === null || $bytes === false) {
            return 'unknown';
        }
        
        $bytes = max((int)$bytes, 0);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    /**
     * Obtiene el número de procesadores
     */
    private function getProcessorCount(): int
    {
        if (function_exists('shell_exec')) {
            $count = shell_exec('nproc 2>/dev/null');
            if ($count) {
                return (int)trim($count);
            }
        }
        return 1; // Valor por defecto
    }
    
    /**
     * Obtiene el límite de memoria de PHP
     */
    private function getPhpMemoryLimit(): ?int
    {
        $memoryLimit = ini_get('memory_limit');
        if (!$memoryLimit || $memoryLimit === '-1') {
            return null;
        }
        
        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int)substr($memoryLimit, 0, -1);
        
        switch ($unit) {
            case 'k': return $value * 1024;
            case 'm': return $value * 1024 * 1024;
            case 'g': return $value * 1024 * 1024 * 1024;
            default: return (int)$memoryLimit;
        }
    }

    public function registerSpringBootLikeMetrics(): void
    {
        // CPU Usage
        $this->recordCpuUsage();
        
        // System Load
        $this->recordSystemLoad();
        
        // Process Uptime
        $this->recordProcessUptime();
        
        // File Descriptors (similar a maxOpenFiles en Spring)
        $this->recordFileDescriptors();
        
        // Thread metrics (simulados en PHP)
        $this->recordThreadMetrics();
        
        // GC Collection (simulado)
        $this->recordGarbageCollection();
        
        // Heap Memory
        $this->recordHeapMemory();
        
        // Database Connections
        $this->recordDatabaseConnections();
        
        // HTTP Sessions (simulado)
        $this->recordHttpSessions();
    }

    /**
     * Registra el uso de CPU (similar a process.cpu.usage en Spring)
     */
    public function recordCpuUsage(): void
    {
        $cpuUsage = 0;
        
        // En Linux podemos obtener el uso de CPU
        if (function_exists('shell_exec')) {
            $cmd = "ps -o %cpu -p " . getmypid();
            $output = shell_exec($cmd);
            if ($output) {
                $lines = explode("\n", trim($output));
                if (isset($lines[1])) {
                    $cpuUsage = floatval($lines[1]) / 100;
                }
            }
        }
        
        $gauge = $this->registry->getOrRegisterGauge(
            'process',
            'cpu_usage',
            'El uso de CPU del proceso actual (0-1)',
            []
        );
        $gauge->set($cpuUsage);
    }
    
    /**
     * Registra la carga del sistema (similar a system.load.average.1m en Spring)
     */
    public function recordSystemLoad(): void
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            
            $gauge1m = $this->registry->getOrRegisterGauge(
                'system',
                'load_average_1m',
                'Carga promedio del sistema en 1 minuto',
                []
            );
            $gauge1m->set($load[0]);
            
            $gauge5m = $this->registry->getOrRegisterGauge(
                'system',
                'load_average_5m',
                'Carga promedio del sistema en 5 minutos',
                []
            );
            $gauge5m->set($load[1]);
            
            $gauge15m = $this->registry->getOrRegisterGauge(
                'system',
                'load_average_15m',
                'Carga promedio del sistema en 15 minutos',
                []
            );
            $gauge15m->set($load[2]);
        }
    }
    
    /**
     * Registra el tiempo de actividad del proceso (similar a process.uptime en Spring)
     */
    public function recordProcessUptime(): void
    {
        // En Linux podemos obtener el tiempo desde que inició el proceso
        $uptime = 0;
        if (function_exists('shell_exec')) {
            $pid = getmypid();
            $cmd = "ps -o etimes= -p $pid";
            $output = trim(shell_exec($cmd));
            if (is_numeric($output)) {
                $uptime = intval($output);
            }
        }
        
        $gauge = $this->registry->getOrRegisterGauge(
            'process',
            'uptime_seconds',
            'Tiempo de actividad del proceso PHP en segundos',
            []
        );
        $gauge->set($uptime);
    }
    
    /**
     * Registra descriptores de archivo (similar a process.files.open en Spring)
     */
    public function recordFileDescriptors(): void
    {
        $openFiles = 0;
        if (function_exists('shell_exec')) {
            $pid = getmypid();
            $cmd = "ls -l /proc/$pid/fd | wc -l";
            $output = trim(shell_exec($cmd));
            if (is_numeric($output)) {
                // Restamos 1 por la línea de cabecera
                $openFiles = max(0, intval($output) - 1);
            }
        }
        
        $gauge = $this->registry->getOrRegisterGauge(
            'process',
            'files_open',
            'Archivos abiertos por el proceso PHP',
            []
        );
        $gauge->set($openFiles);
        
        // Maximum allowed (similar a process.files.max en Spring)
        $maxFiles = 0;
        if (function_exists('shell_exec')) {
            $output = trim(shell_exec("ulimit -n"));
            if (is_numeric($output)) {
                $maxFiles = intval($output);
            }
        }
        
        $gaugeMax = $this->registry->getOrRegisterGauge(
            'process',
            'files_max',
            'Máximo número de archivos que puede abrir el proceso',
            []
        );
        $gaugeMax->set($maxFiles);
    }
    
    /**
     * Registra métricas de hilos (simuladas, PHP no tiene un modelo de hilos como Java)
     */
    public function recordThreadMetrics(): void
    {
        // En PHP no hay threads como en Java, pero podemos simular algunas métricas
        // para compatibilidad con dashboards existentes
        
        $gauge = $this->registry->getOrRegisterGauge(
            'jvm',
            'threads_live',
            'Número de hilos activos (simulado en PHP)',
            []
        );
        // En PHP generalmente hay un hilo por proceso
        $gauge->set(1);
        
        $gaugePeak = $this->registry->getOrRegisterGauge(
            'jvm',
            'threads_peak',
            'Número máximo de hilos (simulado en PHP)',
            []
        );
        $gaugePeak->set(1);
        
        $gaugeDaemon = $this->registry->getOrRegisterGauge(
            'jvm',
            'threads_daemon',
            'Número de hilos daemon (simulado en PHP)',
            []
        );
        $gaugeDaemon->set(0);
    }
    
    /**
     * Registra métricas de recolección de basura (simuladas, PHP no tiene GC como Java)
     */
    public function recordGarbageCollection(): void
    {
        // PHP tiene ciclos de recolección para referencias circulares
        // pero no es como el GC de Java
        
        if (function_exists('gc_collect_cycles')) {
            $collected = gc_collect_cycles();
            
            $counter = $this->registry->getOrRegisterCounter(
                'php',
                'gc_cycles_collected_total',
                'Ciclos de referencia circulares recogidos por el recolector de basura de PHP',
                []
            );
            $counter->incBy($collected);
        }
        
        if (function_exists('gc_status')) {
            $status = gc_status();
            
            if (isset($status['running'])) {
                $gauge = $this->registry->getOrRegisterGauge(
                    'php',
                    'gc_running',
                    'Si el recolector de basura de PHP está ejecutándose',
                    []
                );
                $gauge->set($status['running'] ? 1 : 0);
            }
        }
    }
    
    /**
     * Registra el uso de memoria heap (similar a jvm.memory.used en Spring)
     */
    public function recordHeapMemory(): void
    {
        // Memoria utilizada
        $gauge = $this->registry->getOrRegisterGauge(
            'jvm',
            'memory_used_bytes',
            'Memoria utilizada por PHP (simulando JVM heap)',
            []
        );
        $gauge->set(memory_get_usage(true));
        
        // Memoria máxima
        $memLimit = $this->getPhpMemoryLimit();
        if ($memLimit) {
            $gaugeMax = $this->registry->getOrRegisterGauge(
                'jvm',
                'memory_max_bytes',
                'Memoria máxima configurada para PHP (simulando JVM max heap)',
                []
            );
            $gaugeMax->set($memLimit);
        }
        
        // Memoria pico
        $gaugePeak = $this->registry->getOrRegisterGauge(
            'jvm',
            'memory_peak_bytes',
            'Pico de memoria utilizado por PHP (simulando JVM peak usage)',
            []
        );
        $gaugePeak->set(memory_get_peak_usage(true));
    }
    
    /**
     * Registra conexiones de base de datos (similar a hikaricp.connections en Spring)
     */
    public function recordDatabaseConnections(): void
    {
        if ($this->connection) {
            try {
                // Intentar obtener el número de conexiones activas (varía según el driver)
                $activeConnections = 1; // Por defecto asumimos 1 (la conexión actual)
                
                if (method_exists($this->connection, 'getNativeConnection')) {
                    $wrapped = $this->connection->getNativeConnection();
                    
                    // Intentar detectar conexiones activas según el driver
                    if ($wrapped instanceof \PDO) {
                        $gaugeActive = $this->registry->getOrRegisterGauge(
                            'hikaricp',
                            'connections_active',
                            'Conexiones activas a la base de datos',
                            []
                        );
                        $gaugeActive->set($activeConnections);
                        
                        $gaugeIdle = $this->registry->getOrRegisterGauge(
                            'hikaricp',
                            'connections_idle',
                            'Conexiones inactivas a la base de datos',
                            []
                        );
                        $gaugeIdle->set(0);
                        
                        $gaugeMin = $this->registry->getOrRegisterGauge(
                            'hikaricp',
                            'connections_min',
                            'Conexiones mínimas a la base de datos',
                            []
                        );
                        $gaugeMin->set(0);
                        
                        $gaugeMax = $this->registry->getOrRegisterGauge(
                            'hikaricp',
                            'connections_max',
                            'Conexiones máximas a la base de datos',
                            []
                        );
                        $gaugeMax->set(1);
                    }
                }
            } catch (\Exception $e) {
                // Ignoramos excepciones al intentar obtener métricas de BD
            }
        }
    }
    
    /**
     * Registra sesiones HTTP (simulando metrics.tomcat.sessions en Spring)
     */
    public function recordHttpSessions(): void
    {
        if ($this->container && $this->container->has('session')) {
            try {
                $session = $this->container->get('session');
                
                // Sesiones activas (aquí solo podemos contar la sesión actual)
                $gaugeActive = $this->registry->getOrRegisterGauge(
                    'tomcat',
                    'sessions_active_current',
                    'Sesiones HTTP activas (simulado en PHP)',
                    []
                );
                $gaugeActive->set($session->isStarted() ? 1 : 0);
                
                // Máximo de sesiones
                $gaugeMax = $this->registry->getOrRegisterGauge(
                    'tomcat',
                    'sessions_active_max',
                    'Máximo de sesiones HTTP (simulado en PHP)',
                    []
                );
                $gaugeMax->set(100); // Valor arbitrario para simulación
                
                // Tiempo de creación de sesiones (contador)
                $counterCreated = $this->registry->getOrRegisterCounter(
                    'tomcat',
                    'sessions_created_total',
                    'Total de sesiones HTTP creadas (simulado en PHP)',
                    []
                );
                if ($session->isStarted()) {
                    $counterCreated->inc();
                }
            } catch (\Exception $e) {
                // Ignoramos excepciones al intentar obtener métricas de sesión
            }
        }
    }

}