import numpy as np
import pandas as pd
from shapely.geometry import Point, Polygon
from datetime import datetime, timedelta
import math
from sklearn.ensemble import IsolationForest
import joblib
import os

class LocationValidator:
    def __init__(self, model_path=None):
        self.model_path = model_path or os.path.join(os.path.dirname(__file__), 'saved_models')
        self.fake_gps_model = None
        self.load_models()
        
    def load_models(self):
        """Load pre-trained models"""
        try:
            fake_gps_model_path = os.path.join(self.model_path, 'fake_gps_model.joblib')
            if os.path.exists(fake_gps_model_path):
                self.fake_gps_model = joblib.load(fake_gps_model_path)
        except Exception as e:
            print(f"Error loading models: {e}")
            self.fake_gps_model = IsolationForest(contamination=0.1, random_state=42)
    
    def save_models(self):
        """Save trained models"""
        os.makedirs(self.model_path, exist_ok=True)
        if self.fake_gps_model:
            joblib.dump(self.fake_gps_model, 
                       os.path.join(self.model_path, 'fake_gps_model.joblib'))
    
    def calculate_haversine_distance(self, lat1, lon1, lat2, lon2):
        """Calculate distance between two points using Haversine formula"""
        R = 6371000  # Earth radius in meters
        
        phi1 = math.radians(lat1)
        phi2 = math.radians(lat2)
        delta_phi = math.radians(lat2 - lat1)
        delta_lambda = math.radians(lon2 - lon1)
        
        a = (math.sin(delta_phi/2)**2 + 
             math.cos(phi1) * math.cos(phi2) * math.sin(delta_lambda/2)**2)
        c = 2 * math.atan2(math.sqrt(a), math.sqrt(1-a))
        
        return R * c
    
    def validate_location_within_zone(self, point_lat, point_lon, zone_coordinates):
        """Validate if point is within polygon zone"""
        point = Point(point_lon, point_lat)
        
        # Convert coordinates to polygon
        polygon_coords = [(coord['lng'], coord['lat']) for coord in zone_coordinates]
        polygon = Polygon(polygon_coords)
        
        return polygon.contains(point)
    
    def calculate_distance_to_zone(self, point_lat, point_lon, zone_coordinates):
        """Calculate minimum distance from point to zone boundary"""
        point = Point(point_lon, point_lat)
        
        # Convert coordinates to polygon
        polygon_coords = [(coord['lng'], coord['lat']) for coord in zone_coordinates]
        polygon = Polygon(polygon_coords)
        
        # Calculate distance to polygon boundary
        distance = point.distance(polygon.boundary)
        
        return distance
    
    def detect_fake_gps_sequence(self, locations_data):
        """
        Detect fake GPS from sequence of locations
        locations_data: list of dicts with keys: latitude, longitude, timestamp
        """
        if len(locations_data) < 3:
            return {"is_fake": False, "confidence": 0.0, "reasons": []}
        
        features = []
        anomalies = []
        reasons = []
        
        for i in range(1, len(locations_data)):
            prev = locations_data[i-1]
            curr = locations_data[i]
            
            # Calculate distance and time difference
            distance = self.calculate_haversine_distance(
                prev['latitude'], prev['longitude'],
                curr['latitude'], curr['longitude']
            )
            
            time_diff = (curr['timestamp'] - prev['timestamp']).total_seconds()
            
            # Calculate speed (m/s)
            speed = distance / time_diff if time_diff > 0 else 0
            
            # Calculate acceleration
            if i > 1:
                prev_prev = locations_data[i-2]
                prev_distance = self.calculate_haversine_distance(
                    prev_prev['latitude'], prev_prev['longitude'],
                    prev['latitude'], prev['longitude']
                )
                prev_time_diff = (prev['timestamp'] - prev_prev['timestamp']).total_seconds()
                prev_speed = prev_distance / prev_time_diff if prev_time_diff > 0 else 0
                acceleration = (speed - prev_speed) / time_diff if time_diff > 0 else 0
            else:
                acceleration = 0
            
            # Extract features for anomaly detection
            feature_vector = [
                distance,
                speed,
                acceleration,
                abs(curr['latitude'] - prev['latitude']),
                abs(curr['longitude'] - prev['longitude'])
            ]
            features.append(feature_vector)
            
            # Rule-based checks
            if speed > 50:  # > 180 km/h (unrealistic for PPSU work)
                anomalies.append(i)
                reasons.append(f"Unrealistic speed: {speed*3.6:.1f} km/h")
            
            if acceleration > 10:  # > 10 m/s² (unrealistic acceleration)
                anomalies.append(i)
                reasons.append(f"Unrealistic acceleration: {acceleration:.1f} m/s²")
            
            # Check for teleportation (instant location change)
            if distance > 1000 and time_diff < 10:  # >1km in <10s
                anomalies.append(i)
                reasons.append(f"Teleportation detected: {distance:.0f}m in {time_diff:.1f}s")
        
        # Machine learning anomaly detection
        ml_anomalies = []
        if len(features) >= 3 and self.fake_gps_model:
            try:
                features_array = np.array(features)
                predictions = self.fake_gps_model.predict(features_array)
                ml_anomaly_indices = np.where(predictions == -1)[0]
                ml_anomalies = ml_anomaly_indices.tolist()
                
                for idx in ml_anomaly_indices:
                    if idx not in anomalies:
                        anomalies.append(idx + 1)  # +1 because features start from i=1
                        reasons.append("ML anomaly detection: Suspicious movement pattern")
            except Exception as e:
                print(f"ML anomaly detection error: {e}")
        
        # Calculate confidence score
        total_checks = len(locations_data) - 1
        anomaly_score = len(set(anomalies)) / total_checks if total_checks > 0 else 0
        
        return {
            "is_fake": len(anomalies) > 0,
            "confidence": anomaly_score,
            "anomaly_count": len(set(anomalies)),
            "total_checks": total_checks,
            "reasons": list(set(reasons)),
            "anomaly_indices": list(set(anomalies))
        }
    
    def detect_fake_gps_from_photos(self, photo_metadata_list):
        """
        Detect fake GPS from photo metadata
        photo_metadata_list: list of dicts with EXIF data
        """
        if len(photo_metadata_list) < 2:
            return {"is_fake": False, "confidence": 0.0}
        
        locations = []
        timestamps = []
        
        for meta in photo_metadata_list:
            if 'GPSLatitude' in meta and 'GPSLongitude' in meta:
                locations.append({
                    'latitude': meta['GPSLatitude'],
                    'longitude': meta['GPSLongitude']
                })
                timestamps.append(meta.get('DateTimeOriginal', datetime.now()))
        
        # Check if locations are consistent
        if len(locations) >= 2:
            # Calculate distance between photo locations
            distances = []
            for i in range(1, len(locations)):
                dist = self.calculate_haversine_distance(
                    locations[i-1]['latitude'], locations[i-1]['longitude'],
                    locations[i]['latitude'], locations[i]['longitude']
                )
                distances.append(dist)
            
            # If photos were taken at same location but timestamps show movement
            avg_distance = np.mean(distances) if distances else 0
            time_diff = (timestamps[-1] - timestamps[0]).total_seconds()
            
            # Check for suspicious patterns
            if avg_distance < 10 and time_diff > 3600:  # Same spot for >1 hour
                return {
                    "is_fake": True,
                    "confidence": 0.8,
                    "reason": "Photos taken from same location for extended period"
                }
            
            if avg_distance > 1000 and time_diff < 300:  # Moved >1km in <5min
                return {
                    "is_fake": True,
                    "confidence": 0.9,
                    "reason": f"Rapid movement detected: {avg_distance:.0f}m in {time_diff:.0f}s"
                }
        
        return {"is_fake": False, "confidence": 0.0}
    
    def train_fake_gps_model(self, training_data):
        """
        Train the fake GPS detection model
        training_data: DataFrame with columns: distance, speed, acceleration, lat_diff, lon_diff
        """
        if len(training_data) < 10:
            return False
        
        try:
            features = training_data[['distance', 'speed', 'acceleration', 'lat_diff', 'lon_diff']].values
            
            # Train Isolation Forest
            self.fake_gps_model = IsolationForest(
                n_estimators=100,
                contamination=0.1,
                random_state=42,
                n_jobs=-1
            )
            self.fake_gps_model.fit(features)
            
            # Save the trained model
            self.save_models()
            
            return True
            
        except Exception as e:
            print(f"Error training model: {e}")
            return False
    
    def validate_work_zone_compliance(self, work_locations, assigned_zones):
        """
        Validate if work was done in assigned zones
        work_locations: list of locations where work was performed
        assigned_zones: list of zone polygons assigned to the worker
        """
        compliance_results = []
        
        for location in work_locations:
            point = Point(location['longitude'], location['latitude'])
            in_zone = False
            zone_info = None
            
            for zone in assigned_zones:
                polygon_coords = [(coord['lng'], coord['lat']) for coord in zone['coordinates']]
                polygon = Polygon(polygon_coords)
                
                if polygon.contains(point):
                    in_zone = True
                    zone_info = {
                        'zone_id': zone['id'],
                        'zone_code': zone['zone_code'],
                        'rw_number': zone['rw_number']
                    }
                    break
            
            compliance_results.append({
                'location': location,
                'in_assigned_zone': in_zone,
                'zone_info': zone_info,
                'timestamp': location.get('timestamp', datetime.now())
            })
        
        # Calculate compliance percentage
        total_locations = len(compliance_results)
        compliant_locations = sum(1 for r in compliance_results if r['in_assigned_zone'])
        compliance_rate = compliant_locations / total_locations if total_locations > 0 else 0
        
        return {
            'compliance_rate': compliance_rate,
            'total_locations': total_locations,
            'compliant_locations': compliant_locations,
            'details': compliance_results
        }
    
    def generate_location_heatmap(self, locations_data, grid_size=100):
        """
        Generate heatmap data for visualization
        grid_size: size of grid cells in meters
        """
        if not locations_data:
            return []
        
        # Extract coordinates
        lats = [loc['latitude'] for loc in locations_data]
        lons = [loc['longitude'] for loc in locations_data]
        
        # Create grid
        min_lat, max_lat = min(lats), max(lats)
        min_lon, max_lon = min(lons), max(lons)
        
        # Convert to meters (approximate)
        lat_to_meters = 111000  # 1 degree latitude ≈ 111km
        lon_to_meters = 111000 * math.cos(math.radians(np.mean(lats)))
        
        lat_range_m = (max_lat - min_lat) * lat_to_meters
        lon_range_m = (max_lon - min_lon) * lon_to_meters
        
        grid_rows = int(lat_range_m / grid_size) + 1
        grid_cols = int(lon_range_m / grid_size) + 1
        
        # Initialize grid
        heatmap = np.zeros((grid_rows, grid_cols))
        
        # Populate grid
        for lat, lon in zip(lats, lons):
            row = int(((lat - min_lat) * lat_to_meters) / grid_size)
            col = int(((lon - min_lon) * lon_to_meters) / grid_size)
            
            if 0 <= row < grid_rows and 0 <= col < grid_cols:
                heatmap[row, col] += 1
        
        # Convert to list format for frontend
        heatmap_data = []
        for i in range(grid_rows):
            for j in range(grid_cols):
                if heatmap[i, j] > 0:
                    cell_lat = min_lat + (i * grid_size / lat_to_meters)
                    cell_lon = min_lon + (j * grid_size / lon_to_meters)
                    
                    heatmap_data.append({
                        'latitude': cell_lat + (grid_size / 2 / lat_to_meters),
                        'longitude': cell_lon + (grid_size / 2 / lon_to_meters),
                        'intensity': float(heatmap[i, j]),
                        'count': int(heatmap[i, j])
                    })
        
        return heatmap_data
    
    def calculate_work_route_efficiency(self, route_locations, start_point, end_point):
        """
        Calculate efficiency of work route
        Returns efficiency score (0-100)
        """
        if len(route_locations) < 2:
            return 50.0  # Default score
        
        # Calculate total distance traveled
        total_distance = 0
        for i in range(1, len(route_locations)):
            total_distance += self.calculate_haversine_distance(
                route_locations[i-1]['latitude'], route_locations[i-1]['longitude'],
                route_locations[i]['latitude'], route_locations[i]['longitude']
            )
        
        # Calculate straight-line distance from start to end
        direct_distance = self.calculate_haversine_distance(
            start_point['latitude'], start_point['longitude'],
            end_point['latitude'], end_point['longitude']
        )
        
        # Calculate efficiency (direct distance / total distance)
        if total_distance > 0:
            efficiency = (direct_distance / total_distance) * 100
            efficiency = min(efficiency, 100)  # Cap at 100%
        else:
            efficiency = 100.0
        
        # Penalize for backtracking or circular routes
        unique_locations = len(set((loc['latitude'], loc['longitude']) for loc in route_locations))
        location_utilization = (unique_locations / len(route_locations)) * 100
        
        # Combine metrics
        final_score = (efficiency * 0.6) + (location_utilization * 0.4)
        
        return {
            'efficiency_score': final_score,
            'total_distance_m': total_distance,
            'direct_distance_m': direct_distance,
            'route_efficiency': efficiency,
            'location_utilization': location_utilization,
            'unique_locations': unique_locations,
            'total_points': len(route_locations)
        }