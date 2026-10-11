import AudioToolbox
import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  private var pushChannel: FlutterMethodChannel?
  private var chimeSound: SystemSoundID = 0

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
    guard let registrar = engineBridge.pluginRegistry.registrar(forPlugin: "TedcPush") else { return }
    let channel = FlutterMethodChannel(name: "tedc/push", binaryMessenger: registrar.messenger())
    channel.setMethodCallHandler { [weak self] call, result in
      switch call.method {
      case "chime": result(self?.playChime() ?? false)
      case "clear": result(true)  // Firebase options are only cached natively on Android
      default: result(FlutterMethodNotImplemented)
      }
    }
    pushChannel = channel
  }

  /// The TEDC chime for a notification that arrives while the app is open. A system sound, so the
  /// Ring/Silent switch mutes it like any other alert.
  private func playChime() -> Bool {
    if chimeSound == 0 {
      guard let url = Bundle.main.url(forResource: "tedc_chime", withExtension: "caf") else { return false }
      AudioServicesCreateSystemSoundID(url as CFURL, &chimeSound)
    }
    AudioServicesPlaySystemSound(chimeSound)
    return true
  }
}
