package io.github.nicolopozzato.firefunds

import android.app.Notification
import android.content.pm.PackageManager
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import org.json.JSONObject
import java.time.Instant
import java.time.LocalDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/**
 * Hears every notification the phone shows and keeps only those of the
 * apps the user turned on: queued whole (app, title, text, time) and sent
 * at once. Only when that fails (no network, server down) is the queue
 * left to SendJob, which tries again as soon as Android lets it.
 */
class NotificationReader : NotificationListenerService() {

    override fun onListenerConnected() {
        // After a restart or an update: anything left waiting goes now.
        if (Store(this).queue().length() > 0) send()
    }

    override fun onNotificationPosted(sbn: StatusBarNotification) {
        val store = Store(this)
        if (!store.isEnabled(sbn.packageName)) return
        val notification = sbn.notification
        // Progress bars, music, a group's summary: never a payment.
        if (notification.flags and (Notification.FLAG_ONGOING_EVENT or Notification.FLAG_GROUP_SUMMARY) != 0) return

        val extras = notification.extras
        val title = extras.getCharSequence(Notification.EXTRA_TITLE)?.toString() ?: ""
        val text = (extras.getCharSequence(Notification.EXTRA_BIG_TEXT) ?: extras.getCharSequence(Notification.EXTRA_TEXT))?.toString() ?: ""
        if (title.isBlank() && text.isBlank()) return

        store.recordSeen(sbn.packageName)
        val zone = ZoneId.systemDefault()
        store.enqueue(
            JSONObject()
                .put("package", sbn.packageName)
                .put("appName", label(sbn.packageName))
                .put("title", title)
                .put("text", text)
                .put("postedAt", LocalDateTime.ofInstant(Instant.ofEpochMilli(sbn.postTime), zone).format(DateTimeFormatter.ISO_LOCAL_DATE_TIME))
                .put("timezone", zone.id)
                .put("key", sbn.key + "@" + sbn.postTime),
        )
        send()
    }

    private fun send() {
        Thread {
            if (Server(Store(this)).sendQueue() > 0) SendJob.schedule(this)
        }.start()
    }

    private fun label(packageName: String): String = try {
        packageManager.getApplicationLabel(packageManager.getApplicationInfo(packageName, 0)).toString()
    } catch (e: PackageManager.NameNotFoundException) {
        packageName
    }
}
