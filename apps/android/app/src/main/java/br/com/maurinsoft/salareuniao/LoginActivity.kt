package br.com.maurinsoft.salareuniao

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.View
import android.widget.EditText
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import br.com.maurinsoft.salareuniao.databinding.ActivityLoginBinding
import java.util.concurrent.Executors

class LoginActivity : AppCompatActivity() {
    private lateinit var binding: ActivityLoginBinding
    private lateinit var session: SessionStore
    private lateinit var secure: SecureTokenStore
    private val executor = Executors.newSingleThreadExecutor()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(binding.root)

        session = SessionStore(this)
        secure = SecureTokenStore(this)

        if (handleIncomingIntent(intent)) return

        binding.edtEmail.setText(session.email)
        binding.edtApiBase.setText(session.apiBase)
        binding.edtWebBase.setText(session.webBase)

        binding.btnLogin.setOnClickListener { doLogin() }
        binding.btnOpenInvite.setOnClickListener { openInviteDialog() }

        secure.load()?.let { token ->
            setBusy(true, "Validando sessão...")
            executor.execute {
                try {
                    val api = ApiClient(session.apiBase, token)
                    val me = api.me()
                    session.userName = me.name
                    session.userRole = me.role
                    runOnUiThread { openMain() }
                } catch (_: Exception) {
                    secure.clear()
                    runOnUiThread { setBusy(false, "") }
                }
            }
        }
    }

    private fun handleIncomingIntent(incoming: Intent): Boolean {
        val data = incoming.data ?: return false

        val target = when {
            data.scheme == "salareuniao" && data.host == "join" -> {
                val token = data.getQueryParameter("token") ?: return false
                session.webBase + "/join.php?token=" + Uri.encode(token)
            }
            data.scheme == "https" &&
                data.host.equals(BuildConfig.APP_HOST, ignoreCase = true) &&
                data.path.orEmpty().endsWith("/join.php") -> data.toString()
            else -> return false
        }

        startActivity(
            Intent(this, MeetingActivity::class.java)
                .putExtra(MeetingActivity.EXTRA_URL, target)
        )
        return true
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleIncomingIntent(intent)
    }

    private fun validHttps(url: String): Boolean {
        return try {
            val uri = Uri.parse(url)
            uri.scheme == "https" && !uri.host.isNullOrBlank()
        } catch (_: Exception) {
            false
        }
    }

    private fun saveServerConfig(): Boolean {
        val apiBase = binding.edtApiBase.text.toString().trim().trimEnd('/')
        val webBase = binding.edtWebBase.text.toString().trim().trimEnd('/')
        if (!validHttps(apiBase) || !validHttps(webBase)) {
            binding.txtStatus.text = "Use URLs HTTPS válidas para API e Web."
            return false
        }
        session.apiBase = apiBase
        session.webBase = webBase
        return true
    }

    private fun doLogin() {
        val email = binding.edtEmail.text.toString().trim()
        val password = binding.edtPassword.text.toString()
        if (email.isBlank() || password.isBlank()) {
            binding.txtStatus.text = "Informe e-mail e senha."
            return
        }
        if (!saveServerConfig()) return

        setBusy(true, "Autenticando...")
        executor.execute {
            try {
                val api = ApiClient(session.apiBase)
                val result = api.login(email, password)
                secure.save(result.token)
                session.email = result.user.email
                session.userName = result.user.name
                session.userRole = result.user.role
                runOnUiThread { openMain() }
            } catch (e: Exception) {
                runOnUiThread {
                    setBusy(false, "")
                    binding.txtStatus.text = "Falha no login: ${e.message}"
                }
            }
        }
    }

    private fun openInviteDialog() {
        if (!saveServerConfig()) return
        val input = EditText(this).apply {
            hint = "Cole o link do convite ou o token"
            setSingleLine(false)
        }
        AlertDialog.Builder(this)
            .setTitle("Abrir convite")
            .setView(input)
            .setPositiveButton("Abrir") { _, _ ->
                val raw = input.text.toString().trim()
                if (raw.isBlank()) return@setPositiveButton
                val url = if (raw.startsWith("https://")) {
                    raw
                } else {
                    session.webBase + "/join.php?token=" + Uri.encode(raw)
                }
                if (!validHttps(url)) {
                    binding.txtStatus.text = "Convite inválido."
                    return@setPositiveButton
                }
                startActivity(Intent(this, MeetingActivity::class.java).putExtra(MeetingActivity.EXTRA_URL, url))
            }
            .setNegativeButton("Cancelar", null)
            .show()
    }

    private fun setBusy(busy: Boolean, text: String) {
        binding.progress.visibility = if (busy) View.VISIBLE else View.GONE
        binding.btnLogin.isEnabled = !busy
        binding.btnOpenInvite.isEnabled = !busy
        binding.txtStatus.text = text
    }

    private fun openMain() {
        setBusy(false, "")
        startActivity(Intent(this, MainActivity::class.java))
        finish()
    }

    override fun onDestroy() {
        executor.shutdownNow()
        super.onDestroy()
    }
}
