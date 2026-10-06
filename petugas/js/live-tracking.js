/**
 * Live Tracking Script for PPSU Petugas
 * Sends GPS coordinates to server periodically
 */

(function () {
    const UPDATE_INTERVAL = 15000; // 15 seconds
    const MIN_DISTANCE = 10; // 10 meters
    const API_URL = '../../api/gps/update_location.php';

    let lastSentValues = {
        lat: null,
        lng: null,
        timestamp: 0
    };

    let watchId = null;

    function initTracking() {
        if (!("geolocation" in navigator)) {
            console.error("Geolocation is not supported by this browser.");
            return;
        }

        console.log("Starting live tracking...");

        // Request high accuracy for better results
        const options = {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 0
        };

        watchId = navigator.geolocation.watchPosition(
            handlePositionUpdate,
            handlePositionError,
            options
        );
    }

    function handlePositionUpdate(position) {
        const { latitude, longitude, accuracy, speed, heading, altitude } = position.coords;
        const now = Date.now();

        // Calculate distance from last sent point (simple approximation)
        const dist = calculateDistance(
            lastSentValues.lat,
            lastSentValues.lng,
            latitude,
            longitude
        );

        // Send update if:
        // 1. First time
        // 2. Time elapsed > UPDATE_INTERVAL OR
        // 3. Distance moved > MIN_DISTANCE (and at least 5 seconds elapsed)
        const timeElapsed = now - lastSentValues.timestamp;

        if (
            lastSentValues.timestamp === 0 ||
            timeElapsed > UPDATE_INTERVAL ||
            (dist > MIN_DISTANCE && timeElapsed > 5000)
        ) {
            sendLocationToServer({
                latitude,
                longitude,
                accuracy,
                speed,
                heading,
                altitude,
                timestamp: new Date(position.timestamp).toISOString().slice(0, 19).replace('T', ' ')
            });

            lastSentValues = {
                lat: latitude,
                lng: longitude,
                timestamp: now
            };
        }
    }

    function handlePositionError(error) {
        console.warn(`GPS Error (${error.code}): ${error.message}`);
    }

    function sendLocationToServer(data) {
        fetch(API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    console.log("Location updated:", result.timestamp);
                } else {
                    if (result.user_status && result.user_status !== 'online') {
                        // Stop tracking if user is inactive (optional, but good for battery)
                        // console.log("User inactive, tracking paused.");
                    }
                    console.warn("Server warning:", result.error);
                }
            })
            .catch(error => {
                console.error("Network error sending location:", error);
            });
    }

    // Haversine formula for distance in meters
    function calculateDistance(lat1, lon1, lat2, lon2) {
        if (!lat1 || !lon1) return 99999;

        const R = 6371e3; // metres
        const φ1 = lat1 * Math.PI / 180;
        const φ2 = lat2 * Math.PI / 180;
        const Δφ = (lat2 - lat1) * Math.PI / 180;
        const Δλ = (lon2 - lon1) * Math.PI / 180;

        const a = Math.sin(Δφ / 2) * Math.sin(Δφ / 2) +
            Math.cos(φ1) * Math.cos(φ2) *
            Math.sin(Δλ / 2) * Math.sin(Δλ / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

        return R * c;
    }

    // Initialize on load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTracking);
    } else {
        initTracking();
    }

})();
