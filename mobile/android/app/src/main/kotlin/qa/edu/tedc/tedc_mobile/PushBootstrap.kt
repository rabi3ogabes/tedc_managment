package qa.edu.tedc.tedc_mobile

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.os.Build
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions
import org.json.JSONObject

/**
 * Firebase is configured at runtime from the platform (Settings → Notifications on the dashboard), not from a
 * bundled google-services.json. The Flutter side caches the options here; on every process start — including
 * when the system wakes a closed app for an incoming push — the default FirebaseApp is initialised from them.
 */
object PushBootstrap {
    const val CHANNEL_ID = "tedc_general"
    private const val PREFS = "tedc_push"
    private const val KEY_OPTIONS = "options"

    fun initialize(context: Context) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val raw = prefs.getString(KEY_OPTIONS, null) ?: return
        val json = JSONObject(raw)
        ensureChannel(context, json.optString("channelName", "Notifications"))
        if (FirebaseApp.getApps(context).isNotEmpty()) return
        try {
            FirebaseApp.initializeApp(context, toOptions(json))
        } catch (e: IllegalStateException) {
            // Already initialised by another path.
        } catch (e: IllegalArgumentException) {
            prefs.edit().remove(KEY_OPTIONS).apply() // invalid options: wait for a fresh configuration
        }
    }

    fun save(context: Context, options: Map<String, Any?>) {
        val json = JSONObject(options.filterValues { it != null })
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString(KEY_OPTIONS, json.toString()).apply()
        ensureChannel(context, json.optString("channelName", "Notifications"))
    }

    fun clear(context: Context) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().remove(KEY_OPTIONS).apply()
    }

    private fun toOptions(json: JSONObject): FirebaseOptions {
        val builder = FirebaseOptions.Builder()
            .setApiKey(json.getString("apiKey"))
            .setApplicationId(json.getString("appId"))
            .setGcmSenderId(json.getString("messagingSenderId"))
        json.optString("projectId").takeIf { it.isNotEmpty() }?.let { builder.setProjectId(it) }
        json.optString("storageBucket").takeIf { it.isNotEmpty() }?.let { builder.setStorageBucket(it) }
        return builder.build()
    }

    private fun ensureChannel(context: Context, name: String) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        val channel = NotificationChannel(CHANNEL_ID, name, NotificationManager.IMPORTANCE_HIGH).apply {
            enableVibration(true)
            enableLights(true)
            lightColor = 0xFF8A1538.toInt()
        }
        manager.createNotificationChannel(channel) // updates the name when it changes
    }
}
