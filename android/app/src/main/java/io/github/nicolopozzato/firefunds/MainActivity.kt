package io.github.nicolopozzato.firefunds

import android.app.Activity
import android.content.ComponentName
import android.content.Intent
import android.os.Bundle
import android.provider.Settings
import android.text.InputType
import android.util.TypedValue
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.Switch
import android.widget.TextView
import org.json.JSONObject

/**
 * The one screen: the server and token, the permission to read
 * notifications, which apps to read, and what waits to be sent. Built in
 * code, no layout files or libraries, to keep the app small.
 */
class MainActivity : Activity() {
    private lateinit var store: Store
    private lateinit var status: TextView
    private lateinit var access: TextView
    private lateinit var apps: LinearLayout
    private lateinit var queue: TextView

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        store = Store(this)
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(20), dp(16), dp(20), dp(32))
        }

        root.addView(text(getString(R.string.intro)))

        root.addView(heading(getString(R.string.server)))
        val url = EditText(this).apply {
            hint = getString(R.string.server_url)
            inputType = InputType.TYPE_TEXT_VARIATION_URI
            setText(store.serverUrl)
        }
        val token = EditText(this).apply {
            hint = getString(R.string.token)
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
            setText(store.token)
        }
        status = text("")
        root.addView(url)
        root.addView(token)
        root.addView(Button(this).apply {
            text = getString(R.string.save)
            setOnClickListener {
                store.serverUrl = url.text.toString()
                store.token = token.text.toString()
                check()
            }
        })
        root.addView(status)

        root.addView(heading(getString(R.string.access)))
        access = text("")
        root.addView(access)
        root.addView(Button(this).apply {
            text = getString(R.string.access_open)
            setOnClickListener { startActivity(Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS)) }
        })

        root.addView(heading(getString(R.string.apps)))
        root.addView(text(getString(R.string.apps_hint)))
        apps = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        root.addView(apps)

        queue = text("")
        root.addView(queue)
        root.addView(Button(this).apply {
            text = getString(R.string.send_now)
            setOnClickListener {
                Thread {
                    Server(store).sendQueue()
                    runOnUiThread { refresh() }
                }.start()
            }
        })

        setContentView(ScrollView(this).apply { addView(root) })
        if (store.isConfigured) check()
    }

    override fun onResume() {
        super.onResume()
        refresh()
    }

    private fun refresh() {
        access.text = getString(if (hasAccess()) R.string.access_on else R.string.access_off)
        queue.text = getString(R.string.queue, store.queue().length())
        apps.removeAllViews()
        val seen = store.seenApps()
        if (seen.isEmpty()) apps.addView(text(getString(R.string.apps_none)))
        for (app in seen) {
            apps.addView(Switch(this).apply {
                text = app.label
                isChecked = store.isEnabled(app.packageName)
                setPadding(0, dp(8), 0, dp(8))
                setOnCheckedChangeListener { _, on -> store.setEnabled(app.packageName, on) }
            })
        }
    }

    /** Asks the server who the token is, so a wrong address or token shows at once. */
    private fun check() {
        status.text = getString(R.string.checking)
        val warning = if (store.serverUrl.startsWith("http://")) "\n" + getString(R.string.http_warning) else ""
        Thread {
            val message = try {
                val answer = Server(store).me()
                if (answer.status == 200) {
                    getString(R.string.connected, JSONObject(answer.body).optString("firstName"))
                } else {
                    getString(R.string.not_connected, "HTTP " + answer.status)
                }
            } catch (e: Exception) {
                getString(R.string.not_connected, e.message ?: e.javaClass.simpleName)
            }
            runOnUiThread { status.text = message + warning }
        }.start()
    }

    private fun hasAccess(): Boolean {
        val enabled = Settings.Secure.getString(contentResolver, "enabled_notification_listeners") ?: return false
        val me = ComponentName(this, NotificationReader::class.java).flattenToString()
        return enabled.split(':').any { it == me }
    }

    private fun heading(value: String) = TextView(this).apply {
        text = value
        setTextSize(TypedValue.COMPLEX_UNIT_SP, 18f)
        setPadding(0, dp(24), 0, dp(8))
        typeface = android.graphics.Typeface.DEFAULT_BOLD
    }

    private fun text(value: String) = TextView(this).apply {
        text = value
        setPadding(0, dp(4), 0, dp(4))
        visibility = View.VISIBLE
    }

    private fun dp(value: Int) = (value * resources.displayMetrics.density).toInt()
}
