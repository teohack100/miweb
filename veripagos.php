<?php
/**
 * Clase para integración con Veri Pagos API
 * https://veripagos.com/
 * 
 * Endpoints:
 * - Generar QR: POST /api/bcp/generar-qr
 * - Verificar Estado: POST /api/bcp/verificar-estado-qr
 * - Webhook: Recibe notificación de pago completado
 */

class VeriPagos {
    
    private string $baseUrl = 'https://veripagos.com/api/bcp';
    private string $username;
    private string $password;
    private string $secretKey;
    
    public function __construct() {
        $this->username = VERIPAGOS_USERNAME;
        $this->password = VERIPAGOS_PASSWORD;
        $this->secretKey = VERIPAGOS_SECRET_KEY;
    }
    
    /**
     * Genera un código QR para pago
     * 
     * @param float $monto Monto en Bs (0 = cliente elige monto)
     * @param array $data Datos extra para recuperar en webhook
     * @param string|null $vigencia Formato "dia/hora:minuto" (default: "0/00:15")
     * @param bool $usoUnico Si el QR es para un solo pago
     * @param string|null $detalle Descripción del pago
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
     */
    public function generarQR(
        float $monto, 
        array $data = [], 
        ?string $vigencia = "0/00:15",
        bool $usoUnico = true,
        ?string $detalle = null
    ): array {
        $params = [
            'secret_key' => $this->secretKey,
            'monto' => $monto,
            'uso_unico' => $usoUnico
        ];
        
        if (!empty($data)) {
            $params['data'] = $data;
        }
        
        if ($vigencia) {
            $params['vigencia'] = $vigencia;
        }
        
        if ($detalle) {
            $params['detalle'] = $detalle;
        }
        
        $response = $this->request('/generar-qr', $params);
        
        if ($response['Codigo'] === 0) {
            return [
                'success' => true,
                'data' => [
                    'movimiento_id' => $response['Data']['movimiento_id'],
                    'qr' => $response['Data']['qr'],
                    'qr_image' => 'data:image/png;base64,' . $response['Data']['qr']
                ],
                'message' => $response['Mensaje']
            ];
        }
        
        return [
            'success' => false,
            'data' => null,
            'error' => $response['Mensaje'] ?? 'Error desconocido'
        ];
    }
    
    /**
     * Verifica el estado de un pago
     * 
     * @param string $movimientoId ID del movimiento
     * @return array
     */
    public function verificarEstado(string $movimientoId): array {
        $params = [
            'secret_key' => $this->secretKey,
            'movimiento_id' => $movimientoId
        ];
        
        $response = $this->request('/verificar-estado-qr', $params);
        
        if ($response['Codigo'] === 0) {
            return [
                'success' => true,
                'data' => [
                    'movimiento_id' => $response['Data']['movimiento_id'],
                    'monto' => $response['Data']['monto'],
                    'detalle' => $response['Data']['detalle'],
                    'estado' => $response['Data']['estado'],
                    'estado_notificacion' => $response['Data']['estado_notificacion'],
                    'remitente' => $response['Data']['remitente'] ?? null
                ],
                'message' => $response['Mensaje']
            ];
        }
        
        return [
            'success' => false,
            'data' => null,
            'error' => $response['Mensaje'] ?? 'Error desconocido'
        ];
    }
    
    /**
     * Valida la autenticación del webhook
     * 
     * @return bool
     */
    public function validarWebhookAuth(): bool {
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
            return false;
        }
        
        return $_SERVER['PHP_AUTH_USER'] === $this->username 
            && $_SERVER['PHP_AUTH_PW'] === $this->password;
    }
    
    /**
     * Procesa los datos del webhook
     * 
     * @param array $data Datos recibidos del webhook
     * @return array Datos procesados
     */
    public function procesarWebhookData(array $data): array {
        return [
            'movimiento_id' => $data['movimiento_id'] ?? null,
            'monto' => $data['monto'] ?? 0,
            'detalle' => $data['detalle'] ?? '',
            'estado' => $data['estado'] ?? '',
            'data_extra' => $data['data'] ?? [],
            'remitente' => $data['remitente'] ?? null
        ];
    }
    
    /**
     * Realiza una petición a la API
     * 
     * @param string $endpoint
     * @param array $params
     * @return array
     */
    private function request(string $endpoint, array $params): array {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'Codigo' => 1,
                'Data' => null,
                'Mensaje' => 'Error de conexión: ' . $error
            ];
        }
        
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'Codigo' => 1,
                'Data' => null,
                'Mensaje' => 'Error al decodificar respuesta'
            ];
        }
        
        return $decoded;
    }
}
