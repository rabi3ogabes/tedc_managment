package qa.edu.tedc.tedc_mobile

import android.app.Application

class TedcApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        PushBootstrap.initialize(this)
    }
}
