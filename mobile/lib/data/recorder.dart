import 'dart:async';
import 'dart:io';

import 'package:path_provider/path_provider.dart';
import 'package:record/record.dart';

/// Records the microphone into a small WAV file (16 kHz, mono) that the server turns into words. The server tells the language by itself, so a person
/// can speak Russian, Uzbek or Tajik whatever language the app is shown in. Nothing is kept: the file is deleted as soon as it was read.
class VoiceRecorder {
  final AudioRecorder _rec = AudioRecorder();
  StreamSubscription<Amplitude>? _amp;
  String? _path;

  /// True when the phone allows the microphone (asks the person the first time).
  Future<bool> allowed() async {
    try {
      return await _rec.hasPermission();
    } catch (_) {
      return false;
    }
  }

  /// Starts recording; [onLevel] gets the loudness 0..1 about ten times a second (for the moving bars). False when it could not start.
  Future<bool> start({required void Function(double level) onLevel}) async {
    try {
      if (!await _rec.hasPermission()) return false;
      final dir = await getTemporaryDirectory();
      _path = '${dir.path}/voice_${DateTime.now().millisecondsSinceEpoch}.wav';
      await _rec.start(const RecordConfig(encoder: AudioEncoder.wav, sampleRate: 16000, numChannels: 1), path: _path!);
      _amp = _rec.onAmplitudeChanged(const Duration(milliseconds: 100)).listen((a) => onLevel(((a.current + 50) / 50).clamp(0.0, 1.0)));
      return true;
    } catch (_) {
      _path = null;
      return false;
    }
  }

  /// Stops and returns the recording, or null when there is nothing.
  Future<List<int>?> stop() async {
    await _amp?.cancel();
    _amp = null;
    try {
      final path = await _rec.stop() ?? _path;
      _path = null;
      if (path == null) return null;
      final file = File(path);
      final bytes = await file.readAsBytes();
      unawaited(file.delete().catchError((_) => file));
      return bytes;
    } catch (_) {
      return null;
    }
  }

  /// Stops and throws the recording away.
  Future<void> cancel() async {
    await _amp?.cancel();
    _amp = null;
    try {
      final path = await _rec.stop() ?? _path;
      _path = null;
      if (path != null) unawaited(File(path).delete().catchError((_) => File(path)));
    } catch (_) {}
  }

  Future<void> dispose() async {
    await _amp?.cancel();
    try {
      await _rec.dispose();
    } catch (_) {}
  }
}
