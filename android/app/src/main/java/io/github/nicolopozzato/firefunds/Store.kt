package io.github.nicolopozzato.firefunds

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject
import java.io.File

/**
 * What the app keeps on the phone: the server and token, the apps seen
 * posting notifications (names only) and which of them to read, and the
 * notifications waiting to be sent (a file, so a restart loses none).
 */
class Store(private val context: Context) {
    private val prefs = context.getSharedPreferences("firefunds", Context.MODE_PRIVATE)
    private val queueFile = File(context.filesDir, "queue.json")

    var serverUrl: String
        get() = prefs.getString("server", "") ?: ""
        set(value) = prefs.edit().putString("server", value.trim().trimEnd('/')).apply()

    var token: String
        get() = prefs.getString("token", "") ?: ""
        set(value) = prefs.edit().putString("token", value.trim()).apply()

    val isConfigured: Boolean
        get() = serverUrl.isNotEmpty() && token.isNotEmpty()

    fun isEnabled(packageName: String): Boolean = prefs.getStringSet("enabled", emptySet())!!.contains(packageName)

    fun setEnabled(packageName: String, on: Boolean) {
        val apps = prefs.getStringSet("enabled", emptySet())!!.toMutableSet()
        if (on) apps.add(packageName) else apps.remove(packageName)
        prefs.edit().putStringSet("enabled", apps).apply()
    }

    /** An app posted a notification: its name and when, never what it said. */
    fun recordSeen(packageName: String, label: String) {
        val seen = JSONObject(prefs.getString("seen", "{}") ?: "{}")
        seen.put(packageName, JSONObject().put("label", label).put("at", System.currentTimeMillis()))
        prefs.edit().putString("seen", seen.toString()).apply()
    }

    data class SeenApp(val packageName: String, val label: String, val at: Long)

    /** Most recent first: the bank's app is on top right after its notification. */
    fun seenApps(): List<SeenApp> {
        val seen = JSONObject(prefs.getString("seen", "{}") ?: "{}")
        return seen.keys().asSequence().map { key ->
            val app = seen.getJSONObject(key)
            SeenApp(key, app.optString("label", key), app.optLong("at"))
        }.sortedByDescending { it.at }.toList()
    }

    @Synchronized
    fun enqueue(notification: JSONObject) {
        val queue = queue()
        queue.put(notification)
        // A phone offline for days keeps the latest 500.
        while (queue.length() > 500) queue.remove(0)
        queueFile.writeText(queue.toString())
    }

    @Synchronized
    fun queue(): JSONArray = if (queueFile.exists()) JSONArray(queueFile.readText()) else JSONArray()

    @Synchronized
    fun replaceQueue(left: JSONArray) {
        queueFile.writeText(left.toString())
    }
}
