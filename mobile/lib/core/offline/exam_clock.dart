/// The time spent in an offline exam.
///
/// While the app is open it is measured with a monotonic clock, so changing the phone's clock changes nothing. Time
/// away from the app (in the background, the phone asleep, the app closed) counts too, as an online exam's timer keeps
/// running; that part can only come from the phone's clock, so a clock turned back adds nothing and never takes time
/// off. The server checks the reported time for plausibility as well.
class ExamClock {
  ExamClock({int spent = 0, DateTime? savedAt, DateTime Function()? wall, Duration Function()? monotonic})
      : _wall = wall ?? DateTime.now,
        _monotonic = monotonic ?? _stopwatch() {
    _base = spent + (savedAt == null ? 0 : _forward(_wall().difference(savedAt)));
    _start = _monotonic();
  }

  final DateTime Function() _wall;
  final Duration Function() _monotonic;
  late int _base;
  late Duration _start;
  DateTime? _pausedWall;
  Duration? _pausedMono;

  static Duration Function() _stopwatch() {
    final w = Stopwatch()..start();
    return () => w.elapsed;
  }

  static int _forward(Duration d) => d.isNegative ? 0 : d.inSeconds;

  int get elapsedSeconds => _base + (_monotonic() - _start).inSeconds;

  /// The app went to the background.
  void pause() {
    _pausedWall = _wall();
    _pausedMono = _monotonic();
  }

  /// Back in the app: time away that the monotonic clock did not see (the phone slept) is added.
  void resume() {
    final wall = _pausedWall;
    final mono = _pausedMono;
    _pausedWall = null;
    _pausedMono = null;
    if (wall == null || mono == null) return;
    _base += _forward(_wall().difference(wall) - (_monotonic() - mono));
  }
}
