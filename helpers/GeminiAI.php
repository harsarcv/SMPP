<?php
/**
 * Gemini AI Helper Class
 * Uses Google Gemini API (Free Tier) for image analysis
 * 
 * Features:
 * - Analyze work photos to validate PPSU cleaning activities
 * - Detect if image shows legitimate cleaning work
 * - Generate description of the work activity
 */

class GeminiAI {
    private $apiKey;
    private $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent';
    
    public function __construct() {
        // Load API key from config or environment
        $this->apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : ($_ENV['GEMINI_API_KEY'] ?? '');
    }
    
    /**
     * Set API key manually
     */
    public function setApiKey($key) {
        $this->apiKey = $key;
    }
    
    /**
     * Analyze a work photo to validate PPSU cleaning activity
     * 
     * @param string $imagePath Path to the image file
     * @param string $taskDescription Optional task description to validate photo against
     * @return array [
     *   'success' => bool,
     *   'is_valid_work' => bool,
     *   'description' => string,
     *   'confidence' => float (0-100),
     *   'work_type' => string (e.g., 'membersihkan_sungai', 'membersihkan_got', etc.),
     *   'matches_description' => bool (if taskDescription provided)
     * ]
     */
    public function analyzeWorkPhoto($imagePath, $taskDescription = '') {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'error' => 'API key not configured',
                'is_valid_work' => null,
                'description' => 'AI tidak tersedia',
                'confidence' => 0
            ];
        }
        
        // Check if file exists
        if (!file_exists($imagePath)) {
            return [
                'success' => false,
                'error' => 'Image file not found',
                'is_valid_work' => null,
                'description' => 'File tidak ditemukan',
                'confidence' => 0
            ];
        }
        
        // Read and encode image
        $imageData = file_get_contents($imagePath);
        $base64Image = base64_encode($imageData);
        $mimeType = mime_content_type($imagePath);
        
        // Prepare the prompt - DIFFERENT based on whether task description is provided
        if (!empty($taskDescription)) {
            // VALIDATION AGAINST TASK DESCRIPTION
            $prompt = "Kamu adalah AI validator untuk sistem monitoring Petugas PPSU (Penanganan Prasarana dan Sarana Umum) di Indonesia.

DESKRIPSI TUGAS YANG DIMASUKKAN PETUGAS: \"$taskDescription\"

Analisis foto ini dan tentukan:
1. Apa yang SEBENARNYA terlihat di foto ini? (deskripsikan dengan detail apa yang dilakukan orang-orang di foto)
2. Apakah kegiatan di foto SESUAI dengan deskripsi tugas yang diberikan di atas?
3. Jika TIDAK SESUAI, foto harus DITOLAK (is_valid_work = false)

ATURAN VALIDASI KETAT:
- Jika deskripsi tugas adalah 'menolong warga banjir' tapi foto menunjukkan 'membersihkan got/selokan', maka is_valid_work = FALSE
- Jika deskripsi tugas adalah 'membersihkan jalan' tapi foto menunjukkan 'memotong rumput', maka is_valid_work = FALSE  
- Foto HARUS menunjukkan kegiatan yang SAMA atau SANGAT MIRIP dengan deskripsi tugas
- Jangan validasi foto hanya karena menunjukkan pekerjaan PPSU, tapi harus SESUAI dengan deskripsi tugas

PENTING: Jawab dalam format JSON berikut:
{
    \"is_valid_work\": true/false,
    \"matches_description\": true/false,
    \"actual_activity\": \"apa yang sebenarnya terlihat di foto\",
    \"stated_task\": \"$taskDescription\",
    \"work_type\": \"jenis_pekerjaan\",
    \"description\": \"analisis dalam bahasa Indonesia\",
    \"confidence\": 0-100,
    \"reason\": \"alasan kenapa cocok atau tidak cocok dengan deskripsi tugas\"
}

Jenis pekerjaan yang valid: membersihkan_sungai, membersihkan_got, membersihkan_jalan, memotong_rumput, mengangkut_sampah, membersihkan_kolam, menolong_warga, pekerjaan_lain

INGAT: Set is_valid_work = FALSE jika foto tidak sesuai dengan deskripsi tugas, meskipun fotonya menunjukkan pekerjaan PPSU yang valid!";
        } else {
            // GENERAL PPSU WORK VALIDATION (original behavior)
            $prompt = "Kamu adalah AI validator untuk sistem monitoring Petugas PPSU (Penanganan Prasarana dan Sarana Umum) di Indonesia.

Analisis foto ini dan tentukan:
1. Apakah foto menunjukkan kegiatan pekerjaan PPSU yang valid? (membersihkan sungai, membersihkan got/selokan, membersihkan jalan, memotong rumput, mengangkut sampah, dll)
2. Jenis pekerjaan apa yang terlihat di foto?
3. Berikan deskripsi singkat tentang apa yang terlihat di foto.

PENTING: Jawab dalam format JSON berikut:
{
    \"is_valid_work\": true/false,
    \"work_type\": \"jenis_pekerjaan\",
    \"description\": \"deskripsi singkat dalam bahasa Indonesia\",
    \"confidence\": 0-100,
    \"reason\": \"alasan validasi\"
}

Jenis pekerjaan yang valid: membersihkan_sungai, membersihkan_got, membersihkan_jalan, memotong_rumput, mengangkut_sampah, membersihkan_kolam, pekerjaan_lain

Jika foto TIDAK menunjukkan pekerjaan PPSU (misalnya selfie, foto makanan, foto tidak jelas), set is_valid_work = false.";
        }

        // Prepare request payload
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => $prompt
                        ],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $base64Image
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 1024
            ]
        ];
        
        // Make API request
        $url = $this->baseUrl . '?key=' . $this->apiKey;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'error' => 'CURL Error: ' . $error,
                'is_valid_work' => null,
                'description' => 'Gagal menghubungi AI',
                'confidence' => 0
            ];
        }
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'error' => 'API Error: HTTP ' . $httpCode,
                'is_valid_work' => null,
                'description' => 'Error dari API Gemini',
                'confidence' => 0,
                'raw_response' => $response
            ];
        }
        
        // Parse response
        $data = json_decode($response, true);
        
        if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            return [
                'success' => false,
                'error' => 'Invalid API response format',
                'is_valid_work' => null,
                'description' => 'Format respons tidak valid',
                'confidence' => 0
            ];
        }
        
        $aiText = $data['candidates'][0]['content']['parts'][0]['text'];
        
        // Try to parse JSON from AI response
        // AI might include extra text, so we extract JSON portion
        preg_match('/\{[\s\S]*\}/', $aiText, $matches);
        
        if (empty($matches[0])) {
            return [
                'success' => true,
                'is_valid_work' => null,
                'description' => $aiText,
                'confidence' => 0,
                'raw_text' => $aiText
            ];
        }
        
        $aiResult = json_decode($matches[0], true);
        
        if (!$aiResult) {
            return [
                'success' => true,
                'is_valid_work' => null,
                'description' => $aiText,
                'confidence' => 0,
                'raw_text' => $aiText
            ];
        }
        
        return [
            'success' => true,
            'is_valid_work' => $aiResult['is_valid_work'] ?? false,
            'work_type' => $aiResult['work_type'] ?? 'unknown',
            'description' => $aiResult['description'] ?? '',
            'confidence' => (float)($aiResult['confidence'] ?? 0),
            'reason' => $aiResult['reason'] ?? ''
        ];
    }
    
    /**
     * Analyze multiple photos for a report
     * 
     * @param array $photoPaths Array of image paths
     * @return array Combined analysis result
     */
    public function analyzeReportPhotos($photoPaths) {
        $results = [];
        $validCount = 0;
        $totalConfidence = 0;
        
        foreach ($photoPaths as $path) {
            $result = $this->analyzeWorkPhoto($path);
            $results[] = $result;
            
            if ($result['success'] && $result['is_valid_work']) {
                $validCount++;
            }
            if ($result['success']) {
                $totalConfidence += $result['confidence'];
            }
        }
        
        $avgConfidence = count($results) > 0 ? $totalConfidence / count($results) : 0;
        $allValid = $validCount === count($photoPaths);
        
        return [
            'individual_results' => $results,
            'all_valid' => $allValid,
            'valid_count' => $validCount,
            'total_photos' => count($photoPaths),
            'average_confidence' => round($avgConfidence, 2),
            'overall_score' => $allValid ? $avgConfidence : 0
        ];
    }
}
