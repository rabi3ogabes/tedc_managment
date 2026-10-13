import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:video_player/video_player.dart';

import '../../core/l10n/strings.dart';
import '../../core/theme/app_theme.dart';

/// The landscape, full-screen player for lessons that must be watched full screen. Only time watched here counts;
/// leaving it pauses the video.
class FullscreenVideoPage extends StatefulWidget {
  const FullscreenVideoPage({super.key, required this.controller, required this.controls, required this.onEnter, required this.onLeave});

  final VideoPlayerController controller;

  /// The lesson's own controls (seek lock, speed, pauses), drawn on the dark background.
  final Widget Function(BuildContext context) controls;
  final VoidCallback onEnter;
  final VoidCallback onLeave;

  @override
  State<FullscreenVideoPage> createState() => _FullscreenVideoPageState();
}

class _FullscreenVideoPageState extends State<FullscreenVideoPage> {
  bool _chrome = true;
  Timer? _hide;

  @override
  void initState() {
    super.initState();
    SystemChrome.setPreferredOrientations([DeviceOrientation.landscapeLeft, DeviceOrientation.landscapeRight]);
    SystemChrome.setEnabledSystemUIMode(SystemUiMode.immersiveSticky);
    widget.controller.addListener(_tick);
    widget.onEnter();
    widget.controller.play();
    _later();
  }

  void _tick() {
    if (mounted) setState(() {});
  }

  void _later() {
    _hide?.cancel();
    _hide = Timer(const Duration(seconds: 3), () {
      if (mounted && widget.controller.value.isPlaying) setState(() => _chrome = false);
    });
  }

  @override
  void dispose() {
    _hide?.cancel();
    widget.controller.removeListener(_tick);
    widget.controller.pause();
    widget.onLeave();
    SystemChrome.setPreferredOrientations(const []);
    SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final c = widget.controller;
    return Scaffold(
      backgroundColor: Colors.black,
      body: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: () {
          setState(() => _chrome = !_chrome);
          if (_chrome) _later();
        },
        child: Stack(children: [
          Center(child: AspectRatio(aspectRatio: c.value.aspectRatio == 0 ? 16 / 9 : c.value.aspectRatio, child: VideoPlayer(c))),
          AnimatedOpacity(
            opacity: _chrome ? 1 : 0,
            duration: const Duration(milliseconds: 180),
            child: IgnorePointer(
              ignoring: !_chrome,
              child: Container(
                decoration: const BoxDecoration(gradient: LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: [Colors.black54, Colors.transparent, Colors.transparent, Colors.black87])),
                child: SafeArea(
                  child: Column(children: [
                    Align(
                      alignment: AlignmentDirectional.topStart,
                      child: IconButton(
                        tooltip: context.s.t('course.exitFullscreen'),
                        onPressed: () => Navigator.of(context).maybePop(),
                        icon: const Icon(Icons.fullscreen_exit_rounded, color: Colors.white, size: 30),
                      ),
                    ),
                    const Spacer(),
                    if (!c.value.isPlaying)
                      IconButton.filled(
                        iconSize: 44,
                        onPressed: () {
                          c.play();
                          _later();
                        },
                        icon: const Icon(Icons.play_arrow_rounded),
                        style: IconButton.styleFrom(backgroundColor: Colors.white, foregroundColor: AppColors.navy900),
                      ),
                    const Spacer(),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                      child: Theme(
                        data: ThemeData.dark(useMaterial3: true).copyWith(colorScheme: ColorScheme.dark(primary: AppColors.gold300)),
                        child: DefaultTextStyle.merge(style: const TextStyle(color: Colors.white), child: widget.controls(context)),
                      ),
                    ),
                  ]),
                ),
              ),
            ),
          ),
        ]),
      ),
    );
  }
}
