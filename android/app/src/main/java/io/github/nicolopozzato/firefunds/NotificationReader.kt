package io.github.nicolopozzato.firefunds

import android.app.Notification
import android.content.ComponentName
import android.content.Context
import android.content.pm.PackageManager
import android.os.Bundle
import android.os.Parcelable
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
 *
 * Android (and more so some makers' battery savers) may stop the reader:
 * it asks to be bound again at once, Watchdog checks every 15 minutes, and
 * each time it is back it goes through the notifications still in the
 * shade, so those posted meanwhile aren't lost.
 */
class NotificationReader : NotificationListenerService() {

    override fun onListenerConnected() {
        connected = this
        Watchdog.schedule(this)
        catchUp()
    }

    override fun onListenerDisconnected() {
        if (connected === this) connected = null
        requestRebind(ComponentName(this, NotificationReader::class.java))
    }

    override fun onNotificationPosted(sbn: StatusBarNotification) {
        if (queue(sbn)) send()
    }

    /** The notifications still shown of the apps read, queued unless they were already. */
    fun catchUp() {
        val active = try {
            activeNotifications ?: emptyArray()
        } catch (e: Exception) {
            // Not bound (any more): nothing to read.
            emptyArray()
        }
        var queued = false
        for (sbn in active) queued = queue(sbn) || queued
        if (queued || Store(this).queue().length() > 0) send()
    }

    /** @return true when it was queued (one of the apps read, not seen before) */
    private fun queue(sbn: StatusBarNotification): Boolean {
        val store = Store(this)
        if (!store.isEnabled(sbn.packageName)) return false
        val notification = sbn.notification
        // Progress bars, music, a group's summary: never a payment.
        if (notification.flags and (Notification.FLAG_ONGOING_EVENT or Notification.FLAG_GROUP_SUMMARY) != 0) return false

        val extras = notification.extras
        val title = (extras.getCharSequence(Notification.EXTRA_TITLE_BIG) ?: extras.getCharSequence(Notification.EXTRA_TITLE))?.toString() ?: ""
        val text = text(extras)
        if (title.isBlank() && text.isBlank()) return false

        val zone = ZoneId.systemDefault()
        // The server's limits: one too long would be refused and dropped.
        val queued = store.enqueue(
            JSONObject()
                .put("package", sbn.packageName)
                .put("appName", label(sbn.packageName).take(120))
                .put("title", title.take(1000))
                .put("text", text.take(4000))
                .put("postedAt", LocalDateTime.ofInstant(Instant.ofEpochMilli(sbn.postTime), zone).format(DateTimeFormatter.ISO_LOCAL_DATE_TIME))
                .put("timezone", zone.id)
                .put("key", (sbn.key + "@" + sbn.postTime).takeLast(255)),
        )
        if (queued) store.recordSeen(sbn.packageName)
        return queued
    }

    /**
     * All the notification says: the long text, else the short one, plus
     * the lines of a notification that gathers several (a bank posting
     * "2 new movements" with one line each) and the messages of a chat-like one.
     */
    private fun text(extras: Bundle): String {
        val parts = mutableListOf<String>()
        (extras.getCharSequence(Notification.EXTRA_BIG_TEXT) ?: extras.getCharSequence(Notification.EXTRA_TEXT))?.toString()?.let(parts::add)
        extras.getCharSequenceArray(Notification.EXTRA_TEXT_LINES)?.forEach { parts.add(it.toString()) }
        @Suppress("DEPRECATION")
        extras.getParcelableArray(Notification.EXTRA_MESSAGES)?.forEach { message: Parcelable ->
            (message as? Bundle)?.getCharSequence("text")?.toString()?.let(parts::add)
        }
        return parts.map { it.trim() }.filter { it.isNotEmpty() }.distinct().joinToString("\n")
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

    companion object {
        /** The reader while Android has it bound, null otherwise. */
        @Volatile
        var connected: NotificationReader? = null
            private set

        /** Asks Android to bind the reader again: after the process was killed, it doesn't always by itself. */
        fun rebind(context: Context) {
            requestRebind(ComponentName(context, NotificationReader::class.java))
        }
    }
}
