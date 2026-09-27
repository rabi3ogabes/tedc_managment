package qa.edu.tedc.tedc_mobile

import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "tedc/push").setMethodCallHandler { call, result ->
            when (call.method) {
                "configure" -> {
                    @Suppress("UNCHECKED_CAST")
                    PushBootstrap.save(applicationContext, call.arguments as Map<String, Any?>)
                    result.success(true)
                }
                "clear" -> {
                    PushBootstrap.clear(applicationContext)
                    result.success(true)
                }
                else -> result.notImplemented()
            }
        }
    }
}
