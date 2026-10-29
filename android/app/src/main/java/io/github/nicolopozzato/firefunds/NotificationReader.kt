package io.github.nicolopozzato.firefunds

import android.app.Notification
import android.content.pm.PackageManager
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import org.json.JSONObject
import java.time.LocalDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.Instant

/**
 * Hears every notification the phone shows. Of each app it keeps only the
 * name (to offer it in the list); the notifications of the apps the user
 * turned on are queued whole (app, title, text, time) and sent.
 */
class NotificationReader : NotificationListenerService() {

    override fun onNotificationPosted(sbn: StatusBarNotification) {
        val notification = sbn.notification
        if (sbn.packageName == packageName) return
        // Progress bars, music, a group's summary: never a payment.
        if (notification.flags and (Notification.FLAG_ONGOING_EVENT or Notification.FLAG_GROUP_SUMMARY) != 0) return

        val store = Store(this)
        store.recordSeen(sbn.packageName, label(sbn.packageName))
        if (!store.isEnabled(sbn.packageName)) return

        val extras = notification.extras
        val title = extras.getCharSequence(Notification.EXTRA_TITLE)?.toString() ?: ""
        val text = (extras.getCharSequence(Notification.EXTRA_BIG_TEXT) ?: extras.getCharSequence(Notification.EXTRA_TEXT))?.toString() ?: ""
        if (title.isBlank() && text.isBlank()) return

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
        SendJob.schedule(this)
    }

    private fun label(packageName: String): String = try {
        packageManager.getApplicationLabel(packageManager.getApplicationInfo(packageName, 0)).toString()
    } catch (e: PackageManager.NameNotFoundException) {
        packageName
    }
}
