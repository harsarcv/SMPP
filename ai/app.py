from fastapi import FastAPI
from pydantic import BaseModel
from typing import List
from datetime import datetime
from models.location_validator import LocationValidator
from data_processing.data_preprocessor import DataPreprocessor

app = FastAPI()
validator = LocationValidator()
preprocessor = DataPreprocessor()

class Coord(BaseModel):
    lat: float
    lng: float

class ZoneInsideRequest(BaseModel):
    point_lat: float
    point_lng: float
    zone: List[Coord]

@app.get("/health")
def health():
    return {"status": "ok"}

@app.post("/gps/zone/inside")
def gps_zone_inside(payload: ZoneInsideRequest):
    inside = validator.validate_location_within_zone(payload.point_lat, payload.point_lng, [c.dict() for c in payload.zone])
    distance = validator.calculate_distance_to_zone(payload.point_lat, payload.point_lng, [c.dict() for c in payload.zone])
    return {"inside": inside, "distance": float(distance)}


class LocationPoint(BaseModel):
    latitude: float
    longitude: float
    timestamp: str

class AnalyzeRequest(BaseModel):
    locations: List[LocationPoint]

@app.post("/gps/analyze")
def gps_analyze(payload: AnalyzeRequest):
    # Konversi ke list dict
    points = [p.dict() for p in payload.locations]
    # Preprocess ke dataframe
    df = preprocessor.to_dataframe(points)
    df = preprocessor.normalize(df)
    if 'timestamp' in df.columns:
        # Pastikan tipe datetime
        df['timestamp'] = df['timestamp'].apply(lambda x: x if isinstance(x, datetime) else datetime.fromisoformat(str(x)))
    # Ubah ke list dict
    locations_data = df.to_dict(orient='records')
    # Deteksi fake GPS sequence
    result = validator.detect_fake_gps_sequence(locations_data)
    return result


# ============================================
# KPI Calculation Endpoints
# ============================================
from models.kpi_calculator import KPICalculator
kpi_calculator = KPICalculator()


class KPIRequest(BaseModel):
    user_id: int
    # Data untuk Kehadiran (Presence) - Berbasis Hari
    total_scheduled_days: int
    days_present: int
    
    # Data untuk Penyelesaian (Completion) - Berbasis Tugas
    total_tasks_submitted: int
    total_tasks_approved: int
    
    # Data untuk Ketepatan (Accuracy) - Berbasis AI Validation
    ai_valid_reports: int = 0
    ai_checked_reports: int = 0


@app.post("/kpi/calculate")
def calculate_kpi(payload: KPIRequest):
    """Calculate KPI based on provided data"""
    
    # 1. Calculate Presence Rate (Kehadiran) - 65%
    presence_rate = 0.0
    if payload.total_scheduled_days > 0:
        presence_rate = min(100.0, (payload.days_present / payload.total_scheduled_days) * 100)
    else:
        presence_rate = 100.0 if payload.days_present > 0 else 70.0 # Default if no schedules

    # 2. Calculate Completion Rate (Penyelesaian) - 25%
    completion_rate = 0.0
    if payload.total_tasks_submitted > 0:
        completion_rate = (payload.total_tasks_approved / payload.total_tasks_submitted) * 100
    else:
        completion_rate = 100.0 if payload.total_tasks_submitted == 0 else 0.0

    # 3. Calculate Accuracy/Punctuality Rate (Ketepatan) - 5%
    accuracy_rate = 0.0
    if payload.ai_checked_reports > 0:
        accuracy_rate = (payload.ai_valid_reports / payload.ai_checked_reports) * 100
    else:
        accuracy_rate = 70.0  # Default if no AI data active
    
    # Calculate total score using weights
    weights = kpi_calculator.weights
    total_score = (
        presence_rate * weights['attendance'] +
        completion_rate * weights['completion'] +
        accuracy_rate * weights['timeliness']
    )
    
    # Determine status
    status = 'active' if total_score >= 70 else 'warning' if total_score >= 50 else 'inactive'
    
    return {
        "user_id": payload.user_id,
        "presence_rate": round(presence_rate, 1),
        "completion_rate": round(completion_rate, 1),
        "punctuality_rate": round(accuracy_rate, 1), # Tetap gunakan key ini untuk DB
        "score_total": round(total_score, 1),
        "status": status
    }


class KPIValidateRequest(BaseModel):
    score_total: float


@app.post("/kpi/validate")
def validate_kpi_for_login(payload: KPIValidateRequest):
    """
    AI validates if user can login based on KPI score.
    Returns can_login=True if score >= 70, else False with reason.
    """
    score = payload.score_total
    
    if score >= 70:
        return {
            "can_login": True,
            "score": score,
            "status": "active",
            "message": "KPI score is acceptable"
        }
    else:
        return {
            "can_login": False,
            "score": score,
            "status": "blocked",
            "message": f"KPI score {score} is below minimum threshold (70). Account access denied."
        }


class SingleLocationRequest(BaseModel):
    latitude: float
    longitude: float
    previous_latitude: float = None
    previous_longitude: float = None
    time_diff_seconds: float = None


@app.post("/gps/validate-single")
def validate_single_location(payload: SingleLocationRequest):
    """Validate a single GPS point for fake GPS indicators"""
    is_suspicious = False
    reasons = []
    
    if payload.previous_latitude is not None and payload.time_diff_seconds is not None:
        # Calculate distance and speed
        distance = validator.calculate_haversine_distance(
            payload.previous_latitude, payload.previous_longitude,
            payload.latitude, payload.longitude
        )
        
        if payload.time_diff_seconds > 0:
            speed = distance / payload.time_diff_seconds  # m/s
            speed_kmh = speed * 3.6
            
            # Check for unrealistic speed (> 100 km/h for PPSU worker)
            if speed_kmh > 100:
                is_suspicious = True
                reasons.append(f"Unrealistic speed: {speed_kmh:.1f} km/h")
            
            # Check for teleportation (> 1km in < 10 seconds)
            if distance > 1000 and payload.time_diff_seconds < 10:
                is_suspicious = True
                reasons.append(f"Teleportation: {distance:.0f}m in {payload.time_diff_seconds:.1f}s")
    
    return {
        "is_suspicious": is_suspicious,
        "reasons": reasons,
        "latitude": payload.latitude,
        "longitude": payload.longitude
    }


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8001)

