<?php
/**
 * AIService - PHP Helper for calling AI FastAPI endpoints
 * 
 * This class provides methods to call the Python AI server for:
 * - KPI Calculation
 * - GPS Validation
 * - Fake GPS Detection
 */
class AIService {
    private $baseUrl;
    private $timeout;
    
    public function __construct() {
        // AI server URL - change this if hosted elsewhere
        $this->baseUrl = getenv('AI_SERVER_URL') ?: 'http://localhost:8001';
        $this->timeout = 10; // 10 seconds timeout
    }
    
    /**
     * Make HTTP POST request to AI server
     */
    private function post($endpoint, $data) {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("AIService Error: " . $error);
            return null;
        }
        
        if ($httpCode !== 200) {
            error_log("AIService HTTP Error: " . $httpCode);
            return null;
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Check if AI server is available
     */
    public function isAvailable() {
        $ch = curl_init($this->baseUrl . '/health');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $httpCode === 200;
    }
    
    /**
     * Calculate KPI using AI
     * 
     * @param int $userId
     * @param int $totalScheduledDays (Presence base)
     * @param int $daysPresent (Presence value)
     * @param int $totalTasksSubmitted (Completion base)
     * @param int $totalTasksApproved (Completion value)
     * @param int $aiValidReports (Accuracy value)
     * @param int $aiCheckedReports (Accuracy base)
     * @return array|null
     */
    public function calculateKPI($userId, $totalScheduledDays, $daysPresent, $totalTasksSubmitted, $totalTasksApproved, $aiValidReports = 0, $aiCheckedReports = 0) {
        $data = [
            'user_id' => $userId,
            'total_scheduled_days' => $totalScheduledDays,
            'days_present' => $daysPresent,
            'total_tasks_submitted' => $totalTasksSubmitted,
            'total_tasks_approved' => $totalTasksApproved,
            'ai_valid_reports' => $aiValidReports,
            'ai_checked_reports' => $aiCheckedReports
        ];
        
        return $this->post('/kpi/calculate', $data);
    }
    
    /**
     * Validate single GPS location
     * 
     * @param float $latitude
     * @param float $longitude
     * @param float|null $prevLatitude
     * @param float|null $prevLongitude
     * @param float|null $timeDiffSeconds
     * @return array|null
     */
    public function validateGPSLocation($latitude, $longitude, $prevLatitude = null, $prevLongitude = null, $timeDiffSeconds = null) {
        $data = [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'previous_latitude' => $prevLatitude,
            'previous_longitude' => $prevLongitude,
            'time_diff_seconds' => $timeDiffSeconds
        ];
        
        return $this->post('/gps/validate-single', $data);
    }
    
    /**
     * Check if point is inside a zone
     * 
     * @param float $latitude
     * @param float $longitude
     * @param array $zoneCoordinates Array of {lat, lng} points
     * @return array|null
     */
    public function checkZoneInside($latitude, $longitude, $zoneCoordinates) {
        $data = [
            'point_lat' => $latitude,
            'point_lng' => $longitude,
            'zone' => $zoneCoordinates
        ];
        
        return $this->post('/gps/zone/inside', $data);
    }
    
    /**
     * Analyze GPS sequence for fake GPS detection
     * 
     * @param array $locations Array of {latitude, longitude, timestamp}
     * @return array|null
     */
    public function analyzeGPSSequence($locations) {
        $data = ['locations' => $locations];
        return $this->post('/gps/analyze', $data);
    }
    
    /**
     * Validate KPI for login (AI determines if user can login)
     * 
     * @param float $scoreTotal
     * @return array|null
     */
    public function validateKPIForLogin($scoreTotal) {
        $data = ['score_total' => (float)$scoreTotal];
        return $this->post('/kpi/validate', $data);
    }
}
