import pandas as pd
import numpy as np
from datetime import datetime, timedelta

class KPICalculator:
    def __init__(self, weights=None):
        # Weights based on SOP - MUST sum to 100%
        # Kehadiran: 65% max, penalty -10% per miss
        # Penyelesaian: 25% max, penalty -5% per miss
        # Ketepatan: 10% max, penalty -1% per late (updated from 5% to sum to 100%)
        self.weights = weights or {
            'attendance': 0.65,   # 65% kehadiran
            'completion': 0.25,   # 25% penyelesaian
            'timeliness': 0.10    # 10% ketepatan (adjusted to sum to 100%)
        }
        # Penalty per miss/late (as percentage points deducted from total score)
        self.penalties = {
            'attendance': 10.0,   # -10% per miss from total score
            'completion': 5.0,    # -5% per incomplete task
            'timeliness': 1.0     # -1% per late report
        }

    
    def calculate_attendance_score(self, attendance_data):
        """Hitung skor kehadiran berdasarkan presensi harian"""
        total_days = len(attendance_data)
        present_days = sum(1 for day in attendance_data if day['status'] == 'present')
        
        if total_days == 0:
            return 0
        
        attendance_rate = (present_days / total_days) * 100
        return min(attendance_rate, 100)
    
    def calculate_completion_score(self, tasks_data):
        """Hitung skor penyelesaian tugas"""
        total_tasks = len(tasks_data)
        completed_tasks = sum(1 for task in tasks_data if task['status'] == 'completed')
        
        if total_tasks == 0:
            return 0
        
        completion_rate = (completed_tasks / total_tasks) * 100
        return min(completion_rate, 100)
    
    def calculate_timeliness_score(self, reports_data):
        """Hitung skor ketepatan waktu laporan"""
        on_time = 0
        total_reports = len(reports_data)
        
        for report in reports_data:
            scheduled_time = report['scheduled_time']
            actual_time = report['actual_time']
            
            # Toleransi 15 menit
            time_diff = abs((actual_time - scheduled_time).total_seconds() / 60)
            if time_diff <= 15:
                on_time += 1
        
        if total_reports == 0:
            return 0
        
        timeliness_rate = (on_time / total_reports) * 100
        return min(timeliness_rate, 100)
    
    def calculate_total_kpi(self, user_data):
        """Hitung total KPI berdasarkan 3 komponen dengan bobot"""
        # Calculate raw scores (0-100%)
        attendance_raw = self.calculate_attendance_score(user_data['attendance'])
        completion_raw = self.calculate_completion_score(user_data['tasks'])
        timeliness_raw = self.calculate_timeliness_score(user_data['reports'])
        
        # Calculate WEIGHTED contributions (what each component adds to total)
        attendance_weighted = attendance_raw * self.weights['attendance']
        completion_weighted = completion_raw * self.weights['completion']
        timeliness_weighted = timeliness_raw * self.weights['timeliness']
        
        # Total score is sum of weighted contributions
        total_score = attendance_weighted + completion_weighted + timeliness_weighted
        
        return {
            'total_score': round(total_score, 2),
            'breakdown': {
                # Return WEIGHTED values (what user sees)
                'attendance': round(attendance_weighted, 2),   # Max 65%
                'completion': round(completion_weighted, 2),   # Max 25%
                'timeliness': round(timeliness_weighted, 2)    # Max 10%
            },
            'raw_scores': {
                # Keep raw scores for reference
                'attendance': round(attendance_raw, 2),
                'completion': round(completion_raw, 2),
                'timeliness': round(timeliness_raw, 2)
            },
            'max_weights': {
                'attendance': 65,
                'completion': 25,
                'timeliness': 10
            },
            'status': 'active' if total_score >= 70 else 'inactive'
        }
    
    def batch_calculate_kpi(self, users_data):
        """Hitung KPI untuk semua petugas sekaligus"""
        results = {}
        for user_id, data in users_data.items():
            results[user_id] = self.calculate_total_kpi(data)
        return results