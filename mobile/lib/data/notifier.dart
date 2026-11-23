import 'dart:io' show Platform;

import 'package:flutter_local_notifications/flutter_local_notifications.dart';

/// The phone's own notifications (the ones that appear in the shade): an answer of the AI, the end of a check of the tax cabinet or Didox. Everything here
/// is best effort: a phone that refuses them, or a platform without them, never breaks the app (the same news is also in the app's own notification list).
class Notifier {
  static final _plugin = FlutterLocalNotificationsPlugin();
  static bool _ready = false;
  static bool _asked = false;
  static int _next = 100;

  static Future<bool> _init() async {
    if (_ready) return true;
    try {
      if (!(Platform.isAndroid || Platform.isIOS)) return false;
      await _plugin.initialize(
        const InitializationSettings(
          android: AndroidInitializationSettings('@mipmap/ic_launcher'),
          iOS: DarwinInitializationSettings(requestAlertPermission: false, requestBadgePermission: false, requestSoundPermission: false),
        ),
      );
      _ready = true;
    } catch (_) {
      _ready = false;
    }
    return _ready;
  }

  /// Asks the person, once per run, for the right to show notifications (Android 13 and later, iOS).
  static Future<void> requestPermission() async {
    if (_asked || !await _init()) return;
    _asked = true;
    try {
      await _plugin.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()?.requestNotificationsPermission();
      await _plugin.resolvePlatformSpecificImplementation<IOSFlutterLocalNotificationsPlugin>()?.requestPermissions(alert: true, badge: true, sound: true);
    } catch (_) {
      // not allowed: the app's own list still has the news
    }
  }

  static Future<void> show(String title, String body) async {
    if (!await _init()) return;
    try {
      await _plugin.show(
        _next++,
        title,
        body,
        const NotificationDetails(
          android: AndroidNotificationDetails('bugalter_events', 'Bugalter', channelDescription: 'Answers of the AI and results of the checks', importance: Importance.high, priority: Priority.high),
          iOS: DarwinNotificationDetails(),
        ),
      );
    } catch (_) {
      // best effort
    }
  }
}
