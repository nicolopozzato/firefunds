package io.github.nicolopozzato.firefunds

import android.app.Activity
import android.content.ComponentName
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.text.InputType
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import org.json.JSONObject

/**
 * The home screen: how things stand (server, access to notifications,
 * sending) and the apps being read, each with its latest notification. The
 * server's details open on "Change"; apps are chosen in AppsActivity. Built
 * in code, no layout files or libraries, to keep the app small.
 */
class MainActivity : Activity() {
    private lateinit var store: Store
    private lateinit var ui: Ui
    private lateinit var server: Ui.StatusLine
    private lateinit var serverForm: LinearLayout
    private lateinit var serverToggle: Button
    private lateinit var access: Ui.StatusLine
    private lateinit var accessButton: Button
    private lateinit var sending: Ui.StatusLine
    private lateinit var sendButton: Button
    private lateinit var apps: LinearLayout
    private val handler = Handler(Looper.getMainLooper())
    private val ticker = object : Runnable {
        override fun run() {
            refresh()
            handler.postDelayed(this, 3_000)
        }
    }
    private var checking = false
    private var checkError = ""

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        store = Store(this)
        ui = Ui(this)
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(ui.dp(16), ui.dp(16), ui.dp(16), ui.dp(32))
        }
        root.addView(ui.secondary(getString(R.string.intro)).apply { setPadding(ui.dp(4), 0, ui.dp(4), ui.dp(16)) })

        val status = ui.card()
        server = ui.StatusLine(getString(R.string.server))
        status.addView(server.view)
        serverForm = serverForm()
        status.addView(serverForm)
        serverToggle = textButton(getString(R.string.server_change)) {
            serverForm.visibility = if (serverForm.visibility == View.VISIBLE) View.GONE else View.VISIBLE
        }
        status.addView(serverToggle)
        status.addView(ui.divider())

        access = ui.StatusLine(getString(R.string.access))
        status.addView(access.view)
        accessButton = textButton(getString(R.string.access_open)) { startActivity(Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS)) }
        status.addView(accessButton)
        status.addView(ui.divider())

        sending = ui.StatusLine(getString(R.string.sending))
        status.addView(sending.view)
        sendButton = textButton(getString(R.string.send_now)) {
            Thread {
                Server(store).sendQueue()
                runOnUiThread { refresh() }
            }.start()
        }
        status.addView(sendButton)
        root.addView(status)

        root.addView(LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = android.view.Gravity.CENTER_VERTICAL
            addView(ui.heading(getString(R.string.apps)), LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f))
            addView(textButton(getString(R.string.apps_choose)) { startActivity(Intent(this@MainActivity, AppsActivity::class.java)) })
        })
        apps = ui.card()
        root.addView(apps)
        root.addView(ui.secondary(getString(R.string.apps_hint)).apply { setPadding(ui.dp(4), 0, ui.dp(4), 0) })

        setContentView(ScrollView(this).apply {
            setBackgroundColor(ui.background)
            addView(root)
        })
        window.decorView.setBackgroundColor(ui.background)
    }

    override fun onResume() {
        super.onResume()
        serverForm.visibility = if (store.isConfigured) View.GONE else View.VISIBLE
        renderApps()
        handler.post(ticker)
        if (store.isConfigured) check()
    }

    override fun onPause() {
        super.onPause()
        handler.removeCallbacks(ticker)
    }

    private fun serverForm(): LinearLayout {
        val url = EditText(this).apply {
            hint = getString(R.string.server_url)
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_URI
            setText(store.serverUrl)
        }
        val token = EditText(this).apply {
            hint = getString(R.string.token)
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
            setText(store.token)
        }
        return LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            addView(url)
            addView(token)
            addView(Button(this@MainActivity).apply {
                text = getString(R.string.save)
                setOnClickListener {
                    store.serverUrl = url.text.toString()
                    store.token = token.text.toString()
                    check()
                }
            })
        }
    }

    /** What changes by itself: the server's answer, access, the queue. */
    private fun refresh() {
        val host = Uri.parse(store.serverUrl).host ?: store.serverUrl
        when {
            !store.isConfigured -> server.set(ui.idle, getString(R.string.server_none))
            checking -> server.set(ui.idle, getString(R.string.checking))
            store.connectedAs.isNotEmpty() -> server.set(ui.positive, getString(R.string.connected, store.connectedAs, host))
            else -> server.set(ui.negative, getString(R.string.not_connected, host, checkError))
        }
        if (store.serverUrl.startsWith("http://")) server.detail.append("\n" + getString(R.string.http_warning))
        serverToggle.text = getString(if (store.isConfigured) R.string.server_change else R.string.server_set)

        val on = hasAccess()
        access.set(if (on) ui.positive else ui.negative, getString(if (on) R.string.access_on else R.string.access_off))
        accessButton.visibility = if (on) View.GONE else View.VISIBLE

        val waiting = store.queue().length()
        val last = if (store.lastSentAt > 0) getString(R.string.last_sent, ui.ago(store.lastSentAt)) else getString(R.string.never_sent)
        when {
            waiting == 0 -> sending.set(ui.positive, getString(R.string.all_sent) + "\n" + last)
            store.lastError.isNotEmpty() -> sending.set(ui.warning, resources.getQuantityString(R.plurals.waiting, waiting, waiting) + "\n" + getString(R.string.send_failed, store.lastError))
            else -> sending.set(ui.warning, resources.getQuantityString(R.plurals.waiting, waiting, waiting) + "\n" + last)
        }
        sendButton.visibility = if (waiting > 0) View.VISIBLE else View.GONE
    }

    /** The apps being read, most recent notification first. */
    private fun renderApps() {
        apps.removeAllViews()
        val enabled = store.enabledApps().sortedByDescending { store.lastSeen(it) }
        if (enabled.isEmpty()) {
            apps.addView(ui.secondary(getString(R.string.apps_none)).apply { setPadding(0, ui.dp(12), 0, ui.dp(12)) })
            return
        }
        enabled.forEachIndexed { index, packageName ->
            if (index > 0) apps.addView(ui.divider())
            val app = Ui.app(this, packageName)
            val seen = store.lastSeen(packageName)
            val subtitle = when {
                app == null -> getString(R.string.app_removed)
                seen > 0 -> getString(R.string.last_notification, ui.ago(seen))
                else -> getString(R.string.no_notification)
            }
            apps.addView(ui.appRow(app?.second, app?.first ?: packageName, subtitle, true) { on -> store.setEnabled(packageName, on) })
        }
    }

    /** Asks the server who the token is, so a wrong address or token shows at once. */
    private fun check() {
        checking = true
        refresh()
        Thread {
            try {
                val answer = Server(store).me()
                if (answer.status == 200) {
                    store.connectedAs = JSONObject(answer.body).optString("firstName")
                } else {
                    store.connectedAs = ""
                    checkError = "HTTP " + answer.status
                }
            } catch (e: Exception) {
                store.connectedAs = ""
                checkError = e.message ?: e.javaClass.simpleName
            }
            runOnUiThread {
                checking = false
                if (store.connectedAs.isNotEmpty()) serverForm.visibility = View.GONE
                refresh()
            }
        }.start()
    }

    private fun hasAccess(): Boolean {
        val enabled = Settings.Secure.getString(contentResolver, "enabled_notification_listeners") ?: return false
        val me = ComponentName(this, NotificationReader::class.java).flattenToString()
        return enabled.split(':').any { it == me }
    }

    private fun textButton(label: String, onClick: () -> Unit) = Button(this, null, android.R.attr.borderlessButtonStyle).apply {
        text = label
        setTextColor(getColor(R.color.brand))
        layoutParams = LinearLayout.LayoutParams(LinearLayout.LayoutParams.WRAP_CONTENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply {
            gravity = android.view.Gravity.START
        }
        setOnClickListener { onClick() }
    }
}
