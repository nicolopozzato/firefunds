package io.github.nicolopozzato.firefunds

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject
import java.io.File

/**
 * What the app keeps on the phone: the server and token, which apps to read
 * (and when each last posted a notification, never what it said), how the
 * last send went, and the notifications waiting to be sent (a file, so a
 * restart loses none).
 */
class Store(private val context: Context) {
    private val prefs = context.getSharedPreferences("firefunds", Context.MODE_PRIVATE)
    private val queueFile = File(context.filesDir, "queue.json")
    private val keysFile = File(context.filesDir, "queued-keys.json")

    var serverUrl: String
        get() = prefs.getString("server", "") ?: ""
        set(value) = prefs.edit().putString("server", value.trim().trimEnd('/')).apply()

    var token: String
        get() = prefs.getString("token", "") ?: ""
        set(value) = prefs.edit().putString("token", value.trim()).apply()

    val isConfigured: Boolean
        get() = serverUrl.isNotEmpty() && token.isNotEmpty()

    /** Who the server says the token is, from the last check; empty when it failed. */
    var connectedAs: String
        get() = prefs.getString("connectedAs", "") ?: ""
        set(value) = prefs.edit().putString("connectedAs", value).apply()

    fun enabledApps(): Set<String> = prefs.getStringSet("enabled", emptySet())!!

    fun isEnabled(packageName: String): Boolean = enabledApps().contains(packageName)

    fun setEnabled(packageName: String, on: Boolean) {
        val apps = enabledApps().toMutableSet()
        if (on) apps.add(packageName) else apps.remove(packageName)
        prefs.edit().putStringSet("enabled", apps).apply()
    }

    /** One of the apps read posted a notification: when, never what it said. */
    fun recordSeen(packageName: String) {
        val seen = JSONObject(prefs.getString("seen", "{}") ?: "{}")
        seen.put(packageName, System.currentTimeMillis())
        prefs.edit().putString("seen", seen.toString()).apply()
    }

    /** When the app last posted a notification read here, 0 if never. */
    fun lastSeen(packageName: String): Long {
        val seen = JSONObject(prefs.getString("seen", "{}") ?: "{}")
        // Before 1.1 each app was an object with its name.
        return seen.optJSONObject(packageName)?.optLong("at") ?: seen.optLong(packageName)
    }

    /** The last notification the server took, 0 if none yet. */
    val lastSentAt: Long
        get() = prefs.getLong("lastSentAt", 0)

    /** Why the last try to send failed; empty once one goes through. */
    val lastError: String
        get() = prefs.getString("lastError", "") ?: ""

    fun recordSent() {
        prefs.edit().putLong("lastSentAt", System.currentTimeMillis()).putString("lastError", "").apply()
    }

    fun recordError(error: String) {
        prefs.edit().putString("lastError", error).apply()
    }

    /**
     * Queues a notification unless this one (same key) was queued already:
     * Android may tell the same one twice, and the catch-up on reconnect
     * goes through every notification still in the shade.
     *
     * @return false when it was already queued
     */
    fun enqueue(notification: JSONObject): Boolean = synchronized(LOCK) {
        val key = notification.optString("key")
        val known = recentKeys()
        if (key in known) return false
        known.add(key)
        // Enough to cover the shade and a day of notifications.
        while (known.size > 1000) known.removeAt(0)
        write(keysFile, JSONArray(known).toString())

        val queue = queue()
        queue.put(notification)
        // A phone offline for days keeps the latest 500.
        while (queue.length() > 500) queue.remove(0)
        write(queueFile, queue.toString())
        return true
    }

    fun queue(): JSONArray = synchronized(LOCK) { read(queueFile)?.let(::JSONArray) ?: JSONArray() }

    /** Takes these out of the queue, leaving any that arrived while they were being sent. */
    fun removeFromQueue(keys: Set<String>) = synchronized(LOCK) {
        if (keys.isEmpty()) return
        val queue = queue()
        val left = JSONArray()
        for (i in 0 until queue.length()) {
            val item = queue.getJSONObject(i)
            if (item.optString("key") !in keys) left.put(item)
        }
        write(queueFile, left.toString())
    }

    private fun recentKeys(): MutableList<String> {
        val keys = read(keysFile)?.let(::JSONArray) ?: JSONArray()
        return MutableList(keys.length()) { keys.getString(it) }
    }

    /** The file's text; null when missing or unreadable (cut short by a crash), so one bad file never stops the reading. */
    private fun read(file: File): String? = try {
        file.takeIf { it.exists() }?.readText()?.also { JSONArray(it) }
    } catch (e: Exception) {
        null
    }

    /** Written whole or not at all: a temporary file, then renamed over the old one. */
    private fun write(file: File, text: String) {
        val temp = File(file.path + ".tmp")
        temp.writeText(text)
        if (!temp.renameTo(file)) {
            file.writeText(text)
            temp.delete()
        }
    }

    companion object {
        /** One lock for every Store: the reader, the sender and the screen each make their own. */
        private val LOCK = Any()
    }
}
