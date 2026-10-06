import numpy as np
from sklearn.ensemble import IsolationForest
from datetime import datetime
import math

class FakeGPSDetector:
    def __init__(self):
        self.model = IsolationForest(contamination=0.1, random_state=42)
        
    def calculate_distance(self, lat1, lon1, lat2, lon2):
        """Hitung jarak Haversine antara dua titik GPS"""
        R = 6371000  # Earth radius in meters
        phi1 = math.radians(lat1)
        phi2 = math.radians(lat2)
        delta_phi = math.radians(lat2 - lat1)
        delta_lambda = math.radians(lon2 - lon1)
        
        a = (math.sin(delta_phi/2)**2 + 
             math.cos(phi1) * math.cos(phi2) * math.sin(delta_lambda/2)**2)
        c = 2 * math.atan2(math.sqrt(a), math.sqrt(1-a))
        
        return R * c
        
    def detect_anomalies(self, locations, timestamps):
        """Deteksi anomali GPS berdasarkan pergerakan tidak wajar"""
        features = []
        
        for i in range(1, len(locations)):
            lat1, lon1 = locations[i-1]
            lat2, lon2 = locations[i]
            time_diff = (timestamps[i] - timestamps[i-1]).total_seconds()
            
            # Hitung kecepatan
            distance = self.calculate_distance(lat1, lon1, lat2, lon2)
            speed = distance / time_diff if time_diff > 0 else 0
            
            # Feature engineering
            features.append([
                distance,
                speed,
                abs(lat2 - lat1),
                abs(lon2 - lon1)
            ])
        
        if len(features) > 0:
            features = np.array(features)
            predictions = self.model.fit_predict(features)
            anomalies = np.where(predictions == -1)[0]
            return anomalies.tolist()
        
        return []
    
    def check_location_consistency(self, photo_location, zone_polygon):
        """Validasi apakah lokasi foto berada dalam zona kerja"""
        from shapely.geometry import Point, Polygon
        
        point = Point(photo_location['lng'], photo_location['lat'])
        polygon = Polygon(zone_polygon)
        
        return polygon.contains(point)