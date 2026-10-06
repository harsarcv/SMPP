<?php
function validate_geojson($geojson) {
    if (empty($geojson)) return false;
    
    // Jika sudah array, langsung validasi
    if (is_array($geojson)) {
        $data = $geojson;
    } else {
        // Jika string, decode dulu
        $data = json_decode($geojson, true);
        if (json_last_error() !== JSON_ERROR_NONE) return false;
    }
    
    // Validasi basic GeoJSON structure
    if (!isset($data['type']) || !isset($data['coordinates'])) return false;
    
    $allowed_types = ['Point', 'Polygon', 'LineString', 'MultiLineString', 'MultiPolygon'];
    if (!in_array($data['type'], $allowed_types)) return false;
    
    // Validasi koordinat berdasarkan tipe
    switch ($data['type']) {
        case 'Point':
            return is_array($data['coordinates']) && count($data['coordinates']) === 2;
        
        case 'LineString':
        case 'MultiLineString':
            return is_array($data['coordinates']) && count($data['coordinates']) > 0;
        
        case 'Polygon':
            // Polygon harus memiliki array of rings, minimal 1 ring
            if (!is_array($data['coordinates']) || empty($data['coordinates'])) return false;
            // Ring pertama harus memiliki minimal 4 titik dan tertutup
            if (count($data['coordinates'][0]) < 4) return false;
            // Cek apakah ring tertutup (titik pertama = titik terakhir)
            $first = $data['coordinates'][0][0];
            $last = $data['coordinates'][0][count($data['coordinates'][0]) - 1];
            return $first === $last;
        
        case 'MultiPolygon':
            // MultiPolygon harus memiliki array of polygons
            if (!is_array($data['coordinates']) || empty($data['coordinates'])) return false;
            foreach ($data['coordinates'] as $polygon) {
                if (count($polygon[0]) < 4) return false;
                $first = $polygon[0][0];
                $last = $polygon[0][count($polygon[0]) - 1];
                if ($first !== $last) return false;
            }
            return true;
        
        default:
            return false;
    }
}

function calculate_distance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000; // Radius bumi dalam meter
    
    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);
    
    $dlat = $lat2 - $lat1;
    $dlon = $lon2 - $lon1;
    
    $a = sin($dlat/2) * sin($dlat/2) + 
         cos($lat1) * cos($lat2) * 
         sin($dlon/2) * sin($dlon/2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return $earth_radius * $c;
}

function point_in_polygon($point, $polygon) {
    // Implementasi ray casting algorithm
    $x = $point['lng'];
    $y = $point['lat'];
    
    $inside = false;
    
    for ($i = 0, $j = count($polygon) - 1; $i < count($polygon); $j = $i++) {
        $xi = $polygon[$i][0];
        $yi = $polygon[$i][1];
        $xj = $polygon[$j][0];
        $yj = $polygon[$j][1];
        
        $intersect = (($yi > $y) != ($yj > $y))
            && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);
        
        if ($intersect) {
            $inside = !$inside;
        }
    }
    
    return $inside;
}

function format_coordinate($coord, $type = 'lat') {
    if ($type === 'lat') {
        $direction = $coord >= 0 ? 'N' : 'S';
        $degrees = abs($coord);
        return number_format($degrees, 6) . '° ' . $direction;
    } else {
        $direction = $coord >= 0 ? 'E' : 'W';
        $degrees = abs($coord);
        return number_format($degrees, 6) . '° ' . $direction;
    }
}

// Fungsi baru untuk konversi GeoJSON ke WKT (Well-Known Text)
function geojson_to_wkt($geojson) {
    if (is_string($geojson)) {
        $geojson = json_decode($geojson, true);
    }
    
    if (!validate_geojson($geojson)) {
        return null;
    }
    
    $type = $geojson['type'];
    $coordinates = $geojson['coordinates'];
    
    switch ($type) {
        case 'Point':
            return sprintf('POINT(%f %f)', $coordinates[0], $coordinates[1]);
        
        case 'LineString':
            $points = [];
            foreach ($coordinates as $coord) {
                $points[] = sprintf('%f %f', $coord[0], $coord[1]);
            }
            return sprintf('LINESTRING(%s)', implode(', ', $points));
        
        case 'Polygon':
            $rings = [];
            foreach ($coordinates as $ring) {
                $points = [];
                foreach ($ring as $coord) {
                    $points[] = sprintf('%f %f', $coord[0], $coord[1]);
                }
                $rings[] = sprintf('(%s)', implode(', ', $points));
            }
            return sprintf('POLYGON(%s)', implode(', ', $rings));
        
        case 'MultiLineString':
            $lines = [];
            foreach ($coordinates as $linestring) {
                $points = [];
                foreach ($linestring as $coord) {
                    $points[] = sprintf('%f %f', $coord[0], $coord[1]);
                }
                $lines[] = sprintf('(%s)', implode(', ', $points));
            }
            return sprintf('MULTILINESTRING(%s)', implode(', ', $lines));
        
        case 'MultiPolygon':
            $polygons = [];
            foreach ($coordinates as $polygon) {
                $rings = [];
                foreach ($polygon as $ring) {
                    $points = [];
                    foreach ($ring as $coord) {
                        $points[] = sprintf('%f %f', $coord[0], $coord[1]);
                    }
                    $rings[] = sprintf('(%s)', implode(', ', $points));
                }
                $polygons[] = sprintf('(%s)', implode(', ', $rings));
            }
            return sprintf('MULTIPOLYGON(%s)', implode(', ', $polygons));
        
        default:
            return null;
    }
}

// Fungsi untuk konversi WKT ke GeoJSON
function wkt_to_geojson($wkt) {
    if (empty($wkt)) {
        return null;
    }
    
    // Parse WKT string
    if (preg_match('/^POINT\(([^)]+)\)$/i', $wkt, $matches)) {
        $coords = explode(' ', trim($matches[1]));
        return [
            'type' => 'Point',
            'coordinates' => [(float)$coords[0], (float)$coords[1]]
        ];
    }
    
    if (preg_match('/^LINESTRING\(([^)]+)\)$/i', $wkt, $matches)) {
        $points = explode(',', $matches[1]);
        $coordinates = [];
        foreach ($points as $point) {
            $coords = explode(' ', trim($point));
            $coordinates[] = [(float)$coords[0], (float)$coords[1]];
        }
        return [
            'type' => 'LineString',
            'coordinates' => $coordinates
        ];
    }
    
    if (preg_match('/^POLYGON\(([^)]+)\)$/i', $wkt, $matches)) {
        $rings = [];
        preg_match_all('/\(([^()]+)\)/', $matches[1], $ringMatches);
        
        foreach ($ringMatches[1] as $ringStr) {
            $points = explode(',', $ringStr);
            $ring = [];
            foreach ($points as $point) {
                $coords = explode(' ', trim($point));
                $ring[] = [(float)$coords[0], (float)$coords[1]];
            }
            $rings[] = $ring;
        }
        
        return [
            'type' => 'Polygon',
            'coordinates' => $rings
        ];
    }
    
    return null;
}

// Fungsi untuk menghitung centroid poligon
function polygon_centroid($polygon) {
    if (!is_array($polygon) || count($polygon) < 3) {
        return null;
    }
    
    // Hapus titik terakhir jika sama dengan titik pertama (ring tertutup)
    if ($polygon[0] === $polygon[count($polygon) - 1]) {
        array_pop($polygon);
    }
    
    $total_area = 0;
    $centroid_x = 0;
    $centroid_y = 0;
    
    for ($i = 0, $j = count($polygon) - 1; $i < count($polygon); $j = $i++) {
        $xi = $polygon[$i][0];
        $yi = $polygon[$i][1];
        $xj = $polygon[$j][0];
        $yj = $polygon[$j][1];
        
        $area_segment = ($xi * $yj) - ($xj * $yi);
        $total_area += $area_segment;
        
        $centroid_x += ($xi + $xj) * $area_segment;
        $centroid_y += ($yi + $yj) * $area_segment;
    }
    
    $total_area = $total_area / 2;
    
    if ($total_area == 0) {
        // Jika area nol, kembalikan rata-rata titik
        $sum_x = 0;
        $sum_y = 0;
        foreach ($polygon as $point) {
            $sum_x += $point[0];
            $sum_y += $point[1];
        }
        return [
            'lng' => $sum_x / count($polygon),
            'lat' => $sum_y / count($polygon)
        ];
    }
    
    return [
        'lng' => $centroid_x / (6 * $total_area),
        'lat' => $centroid_y / (6 * $total_area)
    ];
}

// Fungsi untuk menghitung area poligon dalam meter persegi
function polygon_area_sqm($polygon) {
    if (!is_array($polygon) || count($polygon) < 3) {
        return 0;
    }
    
    // Hapus titik terakhir jika sama dengan titik pertama
    if ($polygon[0] === $polygon[count($polygon) - 1]) {
        array_pop($polygon);
    }
    
    $area = 0;
    $earth_radius = 6371000; // Meter
    
    for ($i = 0; $i < count($polygon); $i++) {
        $j = ($i + 1) % count($polygon);
        
        $xi = deg2rad($polygon[$i][0]);
        $yi = deg2rad($polygon[$i][1]);
        $xj = deg2rad($polygon[$j][0]);
        $yj = deg2rad($polygon[$j][1]);
        
        $area += ($xj - $xi) * (2 + sin($yi) + sin($yj));
    }
    
    $area = abs($area * $earth_radius * $earth_radius / 2);
    return $area;
}

// Fungsi untuk memeriksa apakah dua poligon beririsan
function polygons_intersect($polygon1, $polygon2) {
    // Cek bounding box dulu (optimasi)
    $bb1 = polygon_bounding_box($polygon1);
    $bb2 = polygon_bounding_box($polygon2);
    
    if ($bb1['max_lng'] < $bb2['min_lng'] || 
        $bb1['min_lng'] > $bb2['max_lng'] ||
        $bb1['max_lat'] < $bb2['min_lat'] || 
        $bb1['min_lat'] > $bb2['max_lat']) {
        return false;
    }
    
    // Cek apakah ada titik dari polygon1 di dalam polygon2
    foreach ($polygon1 as $point) {
        if (point_in_polygon(['lat' => $point[1], 'lng' => $point[0]], $polygon2)) {
            return true;
        }
    }
    
    // Cek apakah ada titik dari polygon2 di dalam polygon1
    foreach ($polygon2 as $point) {
        if (point_in_polygon(['lat' => $point[1], 'lng' => $point[0]], $polygon1)) {
            return true;
        }
    }
    
    return false;
}

// Fungsi untuk mendapatkan bounding box dari poligon
function polygon_bounding_box($polygon) {
    $min_lng = INF;
    $max_lng = -INF;
    $min_lat = INF;
    $max_lat = -INF;
    
    foreach ($polygon as $point) {
        $lng = $point[0];
        $lat = $point[1];
        
        if ($lng < $min_lng) $min_lng = $lng;
        if ($lng > $max_lng) $max_lng = $lng;
        if ($lat < $min_lat) $min_lat = $lat;
        if ($lat > $max_lat) $max_lat = $lat;
    }
    
    return [
        'min_lng' => $min_lng,
        'max_lng' => $max_lng,
        'min_lat' => $min_lat,
        'max_lat' => $max_lat
    ];
}

// Fungsi untuk menyederhanakan poligon (reduce points)
function simplify_polygon($polygon, $tolerance = 0.0001) {
    if (count($polygon) <= 3) {
        return $polygon;
    }
    
    // Douglas-Peucker algorithm
    $result = [];
    $result[] = $polygon[0];
    
    function douglas_peucker($points, $start, $end, $tolerance, &$result) {
        $max_distance = 0;
        $index = 0;
        
        for ($i = $start + 1; $i < $end; $i++) {
            $distance = perpendicular_distance(
                $points[$i],
                $points[$start],
                $points[$end]
            );
            
            if ($distance > $max_distance) {
                $index = $i;
                $max_distance = $distance;
            }
        }
        
        if ($max_distance > $tolerance) {
            douglas_peucker($points, $start, $index, $tolerance, $result);
            douglas_peucker($points, $index, $end, $tolerance, $result);
        } else {
            $result[] = $points[$end];
        }
    }
    
    // Hapus titik terakhir jika ring tertutup
    $is_closed = ($polygon[0] === $polygon[count($polygon) - 1]);
    if ($is_closed) {
        array_pop($polygon);
    }
    
    douglas_peucker($polygon, 0, count($polygon) - 1, $tolerance, $result);
    
    // Tambah titik pertama lagi jika ring tertutup
    if ($is_closed) {
        $result[] = $polygon[0];
    }
    
    return $result;
}

// Fungsi bantu untuk Douglas-Peucker
function perpendicular_distance($point, $line_start, $line_end) {
    $area = abs(
        ($line_end[1] - $line_start[1]) * $point[0] -
        ($line_end[0] - $line_start[0]) * $point[1] +
        $line_end[0] * $line_start[1] -
        $line_end[1] * $line_start[0]
    );
    
    $line_length = sqrt(
        pow($line_end[1] - $line_start[1], 2) +
        pow($line_end[0] - $line_start[0], 2)
    );
    
    return $line_length > 0 ? $area / $line_length : 0;
}

// Fungsi untuk memformat area
function format_area($area_sqm) {
    if ($area_sqm < 10000) {
        return number_format($area_sqm, 2) . ' m²';
    } else {
        $area_hectare = $area_sqm / 10000;
        return number_format($area_hectare, 2) . ' ha';
    }
}

// Fungsi untuk memvalidasi koordinat
function validate_coordinates($lat, $lng) {
    return ($lat >= -90 && $lat <= 90) && ($lng >= -180 && $lng <= 180);
}

// Fungsi untuk mendapatkan titik tengah antara dua koordinat
function midpoint($lat1, $lng1, $lat2, $lng2) {
    return [
        'lat' => ($lat1 + $lat2) / 2,
        'lng' => ($lng1 + $lng2) / 2
    ];
}
?>