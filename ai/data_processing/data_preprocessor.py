import numpy as np
import pandas as pd
from typing import List, Dict, Any


class DataPreprocessor:
    def to_dataframe(self, points: List[Dict[str, Any]]) -> pd.DataFrame:
        df = pd.DataFrame(points)
        if 'timestamp' in df.columns:
            df['timestamp'] = pd.to_datetime(df['timestamp'], errors='coerce')
        return df

    def normalize(self, df: pd.DataFrame) -> pd.DataFrame:
        out = df.copy()
        if 'latitude' in out.columns:
            out['latitude'] = out['latitude'].astype(float)
        if 'longitude' in out.columns:
            out['longitude'] = out['longitude'].astype(float)
        out = out.dropna(subset=['latitude', 'longitude'])
        return out

    def remove_outliers(self, df: pd.DataFrame, zmax: float = 3.0) -> pd.DataFrame:
        out = df.copy()
        for col in ['latitude', 'longitude']:
            if col in out.columns:
                m = out[col].mean()
                s = out[col].std(ddof=0) or 1.0
                z = (out[col] - m) / s
                out = out[np.abs(z) <= zmax]
        return out

    def compute_features(self, df: pd.DataFrame) -> Dict[str, Any]:
        if df.empty:
            return {'count': 0, 'mean_lat': None, 'mean_lng': None, 'std_lat': None, 'std_lng': None}
        return {
            'count': int(len(df)),
            'mean_lat': float(df['latitude'].mean()) if 'latitude' in df.columns else None,
            'mean_lng': float(df['longitude'].mean()) if 'longitude' in df.columns else None,
            'std_lat': float(df['latitude'].std(ddof=0)) if 'latitude' in df.columns else None,
            'std_lng': float(df['longitude'].std(ddof=0)) if 'longitude' in df.columns else None,
        }
