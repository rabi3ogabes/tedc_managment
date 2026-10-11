package qa.edu.tedc.tedc_mobile

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.ContentResolver
import android.content.Context
import android.media.AudioAttributes
import android.media.AudioManager
import android.media.MediaPlayer
import android.net.Uri
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
    /** The TEDC chime; the server sends this channel to people who keep the sound on. */
    const val ALERTS_CHANNEL_ID = "tedc_alerts"

    /** Silent, for people who switched the sound off (a channel's sound cannot change once it exists). */
    const val QUIET_CHANNEL_ID = "tedc_quiet"

    /** The channel of app versions from before the chime; kept so their notifications still arrive. */
    const val LEGACY_CHANNEL_ID = "tedc_general"
    private const val PREFS = "tedc_push"
    private const val KEY_OPTIONS = "options"

    fun initialize(context: Context) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val raw = prefs.getString(KEY_OPTIONS, null) ?: return
        val json = JSONObject(raw)
        ensureChannels(context, json.optString("channelName", "Notifications"), json.optString("quietName", ""))
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
        ensureChannels(context, json.optString("channelName", "Notifications"), json.optString("quietName", ""))
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

    fun chimeUri(context: Context): Uri =
        Uri.parse("${ContentResolver.SCHEME_ANDROID_RESOURCE}://${context.packageName}/${R.raw.tedc_chime}")

    private fun ensureChannels(context: Context, name: String, quietName: String) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        val sound = AudioAttributes.Builder()
            .setUsage(AudioAttributes.USAGE_NOTIFICATION)
            .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
            .build()
        fun channel(id: String, label: String) = NotificationChannel(id, label, NotificationManager.IMPORTANCE_HIGH).apply {
            enableVibration(true)
            enableLights(true)
            lightColor = 0xFF8A1538.toInt()
        }
        // createNotificationChannel only updates the name of an existing channel, never its sound.
        manager.createNotificationChannel(channel(ALERTS_CHANNEL_ID, name).apply { setSound(chimeUri(context), sound) })
        manager.createNotificationChannel(channel(QUIET_CHANNEL_ID, quietName.ifEmpty { name }).apply { setSound(null, null) })
        if (manager.getNotificationChannel(LEGACY_CHANNEL_ID) != null) {
            manager.createNotificationChannel(channel(LEGACY_CHANNEL_ID, name))
        }
    }

    private var player: MediaPlayer? = null

    /**
     * Plays the chime for a notification that arrives while the app is open. Silent when the phone is on silent
     * or vibrate, or Do Not Disturb filters notifications.
     */
    fun chime(context: Context): Boolean {
        val audio = context.getSystemService(Context.AUDIO_SERVICE) as AudioManager
        if (audio.ringerMode != AudioManager.RINGER_MODE_NORMAL) return false
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && manager.currentInterruptionFilter > NotificationManager.INTERRUPTION_FILTER_ALL) return false
        player?.release()
        player = MediaPlayer().apply {
            setAudioAttributes(AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION_EVENT).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build())
            setDataSource(context, chimeUri(context))
            setOnCompletionListener { it.release(); if (player === it) player = null }
            prepare()
            start()
        }
        return true
    }
}
