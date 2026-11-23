import 'dart:async';

import 'package:flutter/foundation.dart' show debugPrint;
import 'package:flutter_tts/flutter_tts.dart';
import 'package:speech_to_text/speech_to_text.dart' as stt;

/// Voice for the AI chat, done by the phone itself: the microphone turns speech into words, and the answer can be read aloud.
/// Nothing is uploaded by the app; the system speech service of the phone does the work.
class VoiceService {
  final stt.SpeechToText _stt = stt.SpeechToText();
  FlutterTts? _tts;
  bool _ready = false;
  List<stt.LocaleName> _locales = const [];

  /// Set when the system reports an error or the end of a listening session.
  void Function(String status)? onStatus;
  void Function(String error)? onError;

  bool get isListening => _stt.isListening;

  /// Prepares recognition (asks for the microphone). False when it is not available or not allowed.
  Future<bool> init() async {
    if (_ready) return true;
    try {
      _ready = await _stt.initialize(
        onStatus: (s) => onStatus?.call(s),
        onError: (e) => onError?.call(e.errorMsg),
      );
      if (_ready) _locales = await _stt.locales();
    } catch (e) {
      debugPrint('Speech recognition is not available: $e');
      _ready = false;
    }
    return _ready;
  }

  /// True when the system says the app has no microphone permission (so the UI can explain it).
  Future<bool> get micDenied async {
    try {
      return !(await _stt.hasPermission);
    } catch (_) {
      return false;
    }
  }

  /// The recogniser's locale id for the app language; Tajik falls back to Russian when the phone has no Tajik model.
  String? localeIdFor(String app) {
    final wanted = switch (app) {
      'uz' => ['uz_UZ', 'uz'],
      'tg' => ['tg_TJ', 'tg', 'ru_RU'],
      _ => ['ru_RU', 'ru'],
    };
    String norm(String s) => s.replaceAll('-', '_').toLowerCase();
    for (final w in wanted) {
      for (final l in _locales) {
        if (norm(l.localeId) == norm(w)) return l.localeId;
      }
    }
    for (final w in wanted) {
      for (final l in _locales) {
        if (norm(l.localeId).startsWith('${norm(w).split('_').first}_')) return l.localeId;
      }
    }
    return null;
  }

  Future<void> listen({
    required String appLocale,
    required void Function(String words, bool isFinal) onWords,
    required void Function(double level) onLevel,
  }) async {
    await _stt.listen(
      onResult: (r) => onWords(r.recognizedWords, r.finalResult),
      onSoundLevelChange: (db) => onLevel(_level(db)),
      listenOptions: stt.SpeechListenOptions(
        partialResults: true,
        cancelOnError: true,
        listenMode: stt.ListenMode.dictation,
        localeId: localeIdFor(appLocale),
        listenFor: const Duration(minutes: 2),
        pauseFor: const Duration(seconds: 5),
      ),
    );
  }

  /// Android reports about -2..10, iOS about -50..0; both become 0..1.
  double _level(double db) => db < -20 ? ((db + 60) / 60).clamp(0.0, 1.0) : ((db + 2) / 12).clamp(0.0, 1.0);

  Future<void> stop() async {
    try {
      await _stt.stop();
    } catch (_) {}
  }

  Future<void> cancel() async {
    try {
      await _stt.cancel();
    } catch (_) {}
  }

  // ───────────── read aloud ─────────────

  /// Reads [text] aloud. False when the phone has no voice for the language.
  Future<bool> speak(String text, String appLocale, {void Function()? onDone}) async {
    try {
      final tts = _tts ??= FlutterTts();
      final lang = switch (appLocale) { 'uz' => 'uz-UZ', _ => 'ru-RU' };
      final ok = await tts.isLanguageAvailable(lang);
      if (ok != true) return false;
      await tts.setLanguage(lang);
      await tts.setSpeechRate(.48);
      tts.setCompletionHandler(() => onDone?.call());
      tts.setCancelHandler(() => onDone?.call());
      tts.setErrorHandler((_) => onDone?.call());
      await tts.speak(text);
      return true;
    } catch (e) {
      debugPrint('Text to speech failed: $e');
      return false;
    }
  }

  Future<void> stopSpeaking() async {
    try {
      await _tts?.stop();
    } catch (_) {}
  }

  Future<void> dispose() async {
    await cancel();
    await stopSpeaking();
  }
}
