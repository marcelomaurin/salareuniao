package br.com.maurinsoft.salareuniao

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.view.View
import android.widget.ArrayAdapter
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import br.com.maurinsoft.salareuniao.databinding.ActivityMainBinding
import java.util.concurrent.Executors

class MainActivity : AppCompatActivity() {
    private lateinit var binding: ActivityMainBinding
    private lateinit var session: SessionStore
    private lateinit var secure: SecureTokenStore
    private lateinit var api: ApiClient
    private val executor = Executors.newSingleThreadExecutor()
    private var rooms: List<RoomInfo> = emptyList()
    private var selectedIndex = -1
    private var pendingUpdate: AndroidUpdateInfo? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        session = SessionStore(this)
        secure = SecureTokenStore(this)
        val token = secure.load()
        if (token.isNullOrBlank()) {
            backToLogin()
            return
        }
        api = ApiClient(session.apiBase, token)

        binding.txtUser.text = "${session.userName} [${session.userRole}]"
        binding.listRooms.setOnItemClickListener { _, _, position, _ -> selectedIndex = position }

        binding.btnRefresh.setOnClickListener { refresh() }
        binding.btnNewRoom.setOnClickListener { createRoomDialog() }
        binding.btnEnter.setOnClickListener { enterSelected() }
        binding.btnInvites.setOnClickListener { openInvites() }
        binding.btnOpen.setOnClickListener { roomAction("open") }
        binding.btnClose.setOnClickListener { confirmRoomAction("close", "Encerrar a reunião?") }
        binding.btnCancel.setOnClickListener { confirmRoomAction("cancel", "Cancelar a reunião?") }
        binding.btnLogout.setOnClickListener { logout() }

        NotificationHelper.ensureChannels(this)
        requestNotificationPermission()
        UpdateScheduler.ensure(this)
        refresh()
    }

    private fun selectedRoom(): RoomInfo? = rooms.getOrNull(selectedIndex)

    private fun refresh() {
        setBusy(true)
        executor.execute {
            try {
                val loadedRooms = api.rooms()
                val agenda = api.agenda(30)
                val update = try { api.androidUpdate() } catch (_: Exception) { null }
                runOnUiThread {
                    ReminderScheduler.schedule(this, agenda)
                    if (update != null) offerUpdate(update)
                    rooms = loadedRooms
                    selectedIndex = -1
                    val lines = rooms.map {
                        "#${it.id}  ${it.name}\n${it.startsAt.ifBlank { "sem horário" }} · ${it.status} · online ${it.onlineCount}"
                    }
                    binding.listRooms.adapter = ArrayAdapter(
                        this,
                        android.R.layout.simple_list_item_single_choice,
                        lines
                    )
                    binding.listRooms.clearChoices()
                    binding.txtAgenda.text = if (agenda.isEmpty()) {
                        "Agenda: nenhuma reunião próxima"
                    } else {
                        "Agenda: " + agenda.take(3).joinToString("  |  ") {
                            "${it.name} ${it.startsAt}"
                        }
                    }
                    setBusy(false)
                }
            } catch (e: Exception) {
                runOnUiThread {
                    setBusy(false)
                    toast("Erro ao atualizar: ${e.message}")
                }
            }
        }
    }

    private fun createRoomDialog() {
        val name = EditText(this).apply { hint = "Nome da sala" }
        val description = EditText(this).apply { hint = "Descrição" }
        val starts = EditText(this).apply { hint = "AAAA-MM-DD HH:MM:SS (opcional)" }
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            val p = (16 * resources.displayMetrics.density).toInt()
            setPadding(p, p / 2, p, 0)
            addView(name)
            addView(description)
            addView(starts)
        }

        AlertDialog.Builder(this)
            .setTitle("Nova sala")
            .setView(box)
            .setPositiveButton("Criar") { _, _ ->
                val roomName = name.text.toString().trim()
                if (roomName.isBlank()) {
                    toast("Informe o nome.")
                    return@setPositiveButton
                }
                setBusy(true)
                executor.execute {
                    try {
                        api.createRoom(
                            roomName,
                            description.text.toString().trim(),
                            starts.text.toString().trim().ifBlank { null }
                        )
                        runOnUiThread { refresh() }
                    } catch (e: Exception) {
                        runOnUiThread {
                            setBusy(false)
                            toast("Falha ao criar sala: ${e.message}")
                        }
                    }
                }
            }
            .setNegativeButton("Cancelar", null)
            .show()
    }

    private fun openInvites() {
        val room = selectedRoom() ?: run {
            toast("Selecione uma sala.")
            return
        }
        startActivity(
            Intent(this, InvitesActivity::class.java)
                .putExtra(InvitesActivity.EXTRA_ROOM_ID, room.id)
                .putExtra(InvitesActivity.EXTRA_ROOM_NAME, room.name)
        )
    }

    private fun requestNotificationPermission() {
        if (
            Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(
                this,
                Manifest.permission.POST_NOTIFICATIONS
            ) != PackageManager.PERMISSION_GRANTED
        ) {
            ActivityCompat.requestPermissions(
                this,
                arrayOf(Manifest.permission.POST_NOTIFICATIONS),
                4201
            )
        }
    }

    private fun offerUpdate(info: AndroidUpdateInfo) {
        if (!info.enabled || info.versionCode <= BuildConfig.VERSION_CODE || info.apkUrl.isBlank()) {
            return
        }
        if (!info.required && session.lastUpdateOfferedCode == info.versionCode) return

        val message = buildString {
            append("Nova versão: ")
            append(info.version)
            if (info.notes.isNotBlank()) {
                append("\n\n")
                append(info.notes)
            }
            if (info.required) {
                append("\n\nEsta atualização foi marcada como obrigatória.")
            }
        }

        val builder = AlertDialog.Builder(this)
            .setTitle("Atualização disponível")
            .setMessage(message)
            .setPositiveButton("Baixar") { _, _ ->
                beginUpdate(info)
            }

        if (!info.required) {
            builder.setNegativeButton("Depois") { _, _ ->
                session.lastUpdateOfferedCode = info.versionCode
            }
        } else {
            builder.setCancelable(false)
        }
        builder.show()
    }

    private fun beginUpdate(info: AndroidUpdateInfo) {
        if (!ApkUpdateManager.canInstallPackages(this)) {
            pendingUpdate = info
            toast("Autorize o Sala Reunião a instalar atualizações e retorne ao app.")
            ApkUpdateManager.requestInstallPermission(this)
            return
        }

        val token = secure.load() ?: run {
            backToLogin()
            return
        }

        setBusy(true)
        executor.execute {
            try {
                val file = ApkUpdateManager.download(this, info, token, session)
                runOnUiThread {
                    setBusy(false)
                    session.lastUpdateOfferedCode = info.versionCode
                    ApkUpdateManager.install(this, file)
                }
            } catch (e: Exception) {
                runOnUiThread {
                    setBusy(false)
                    toast("Falha na atualização: " + e.message)
                }
            }
        }
    }

    override fun onResume() {
        super.onResume()
        val update = pendingUpdate
        if (update != null && ApkUpdateManager.canInstallPackages(this)) {
            pendingUpdate = null
            beginUpdate(update)
        }
    }

    private fun enterSelected() {
        val room = selectedRoom() ?: run {
            toast("Selecione uma sala.")
            return
        }
        setBusy(true)
        executor.execute {
            try {
                if (room.status != "open") api.roomAction(room.id, "open")
                val token = api.hostJoinToken(room.id)
                if (token.isBlank()) throw ApiException("token do anfitrião indisponível")
                val url = session.webBase + "/room.php?token=" + Uri.encode(token)
                runOnUiThread {
                    setBusy(false)
                    startActivity(
                        Intent(this, MeetingActivity::class.java)
                            .putExtra(MeetingActivity.EXTRA_URL, url)
                    )
                }
            } catch (e: Exception) {
                runOnUiThread {
                    setBusy(false)
                    toast("Não foi possível entrar: ${e.message}")
                }
            }
        }
    }

    private fun roomAction(action: String) {
        val room = selectedRoom() ?: run {
            toast("Selecione uma sala.")
            return
        }
        setBusy(true)
        executor.execute {
            try {
                api.roomAction(room.id, action)
                runOnUiThread { refresh() }
            } catch (e: Exception) {
                runOnUiThread {
                    setBusy(false)
                    toast("Falha: ${e.message}")
                }
            }
        }
    }

    private fun confirmRoomAction(action: String, message: String) {
        if (selectedRoom() == null) {
            toast("Selecione uma sala.")
            return
        }
        AlertDialog.Builder(this)
            .setMessage(message)
            .setPositiveButton("Sim") { _, _ -> roomAction(action) }
            .setNegativeButton("Não", null)
            .show()
    }

    private fun logout() {
        setBusy(true)
        executor.execute {
            try {
                api.logout()
            } catch (_: Exception) {
            }
            secure.clear()
            runOnUiThread { backToLogin() }
        }
    }

    private fun backToLogin() {
        startActivity(Intent(this, LoginActivity::class.java))
        finish()
    }

    private fun setBusy(busy: Boolean) {
        binding.progress.visibility = if (busy) View.VISIBLE else View.GONE
    }

    private fun toast(text: String) {
        Toast.makeText(this, text, Toast.LENGTH_LONG).show()
    }

    override fun onDestroy() {
        executor.shutdownNow()
        super.onDestroy()
    }
}
