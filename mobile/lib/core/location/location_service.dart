import 'package:geolocator/geolocator.dart';

/// Why the phone's position could not be read.
enum LocationIssue { servicesOff, denied, deniedForever, timeout }

/// A position fix from the device.
class LocationFix {
  const LocationFix({required this.latitude, required this.longitude, required this.accuracy, required this.mocked});

  final double latitude;
  final double longitude;

  /// Estimated horizontal error in metres.
  final double accuracy;

  /// True when the fix comes from a mock-location app (Android).
  final bool mocked;

  Map<String, dynamic> toJson() => {'latitude': latitude, 'longitude': longitude, 'accuracy': accuracy, 'mocked': mocked};
}

/// Reads the device position for attendance: asks for permission when needed and reports a precise reason
/// when it cannot, so the screen can tell the participant what to fix.
class LocationService {
  const LocationService._();

  static Future<({LocationFix? fix, LocationIssue? issue})> current() async {
    if (!await Geolocator.isLocationServiceEnabled()) return (fix: null, issue: LocationIssue.servicesOff);

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) permission = await Geolocator.requestPermission();
    if (permission == LocationPermission.deniedForever) return (fix: null, issue: LocationIssue.deniedForever);
    if (permission == LocationPermission.denied || permission == LocationPermission.unableToDetermine) {
      return (fix: null, issue: LocationIssue.denied);
    }

    try {
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 15)),
      );
      return (
        fix: LocationFix(latitude: position.latitude, longitude: position.longitude, accuracy: position.accuracy, mocked: position.isMocked),
        issue: null,
      );
    } catch (_) {
      return (fix: null, issue: LocationIssue.timeout);
    }
  }

  /// Opens the screen where the participant can turn location on (or allow it for the app).
  static Future<void> openSettings(LocationIssue issue) async {
    if (issue == LocationIssue.servicesOff) {
      await Geolocator.openLocationSettings();
    } else {
      await Geolocator.openAppSettings();
    }
  }
}
