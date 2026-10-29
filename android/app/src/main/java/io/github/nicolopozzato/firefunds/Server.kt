package io.github.nicolopozzato.firefunds

import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

/** The FireFunds server, with the user's API token. */
class Server(private val store: Store) {

    class Answer(val status: Int, val body: String)

    fun me(): Answer = call("GET", "/api/auth/me", null)

    /**
     * Sends what waits, oldest first: one the server took (or refused as
     * wrong: it would be refused again) leaves the queue; on no network, a
     * server down or a token refused, it stays for the next try. One send at
     * a time: a notification arriving meanwhile waits for the next.
     *
     * @return how many are left
     */
    fun sendQueue(): Int = synchronized(LOCK) {
        if (!store.isConfigured) return store.queue().length()
        val queue = store.queue()
        val done = mutableSetOf<String>()
        for (i in 0 until queue.length()) {
            val item = queue.getJSONObject(i)
            val status = try {
                call("POST", "/api/bank-notifications", item).status
            } catch (e: Exception) {
                store.recordError(e.message ?: e.javaClass.simpleName)
                break
            }
            if (status == 401 || status == 403 || status == 408 || status == 429 || status >= 500) {
                store.recordError("HTTP $status")
                break
            }
            done.add(item.optString("key"))
            if (status < 300) store.recordSent()
        }
        store.removeFromQueue(done)
        return store.queue().length()
    }

    private fun call(method: String, path: String, body: JSONObject?): Answer {
        val connection = URL(store.serverUrl + path).openConnection() as HttpURLConnection
        try {
            connection.requestMethod = method
            connection.connectTimeout = 15_000
            connection.readTimeout = 20_000
            connection.setRequestProperty("Authorization", "Bearer " + store.token)
            connection.setRequestProperty("Accept", "application/json")
            if (body != null) {
                connection.doOutput = true
                connection.setRequestProperty("Content-Type", "application/json")
                connection.outputStream.use { it.write(body.toString().toByteArray()) }
            }
            val status = connection.responseCode
            val stream = if (status < 400) connection.inputStream else connection.errorStream
            return Answer(status, stream?.bufferedReader()?.use { it.readText() } ?: "")
        } finally {
            connection.disconnect()
        }
    }

    companion object {
        private val LOCK = Any()
    }
}
