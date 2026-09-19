package br.com.maurinsoft.salareuniao

import android.os.Bundle
import android.view.View
import android.widget.ArrayAdapter
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import br.com.maurinsoft.salareuniao.databinding.ActivityInvitesBinding
import java.util.concurrent.Executors

class InvitesActivity : AppCompatActivity() {
    companion object {
        const val EXTRA_ROOM_ID = "room_id"
        const val EXTRA_ROOM_NAME = "room_name"
    }

    private lateinit var binding: ActivityInvitesBinding
    private lateinit var api: ApiClient
    private lateinit var secure: SecureTokenStore
    private lateinit var session: SessionStore
    private val executor = Executors.newSingleThreadExecutor()
    private var roomId: Long = 0
    private var invites: List<InviteInfo> = emptyList()
    private var selectedIndex = -1

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityInvitesBinding.inflate(layoutInflater)
        setContentView(binding.root)

        roomId = intent.getLongExtra(EXTRA_ROOM_ID, 0L)
        if (roomId <= 0) {
            finish()
            return
        }

        session = SessionStore(this)
        secure = SecureTokenStore(this)
        val token = secure.load()
        if (token.isNullOrBlank()) {
            finish()
            return
        }
        api = ApiClient(session.apiBase, token)

        val roomName = intent.getStringExtra(EXTRA_ROOM_NAME) ?: ("Sala #" + roomId)
        binding.txtTitle.text = "Convidados · " + roomName
        binding.listInvites.setOnItemClickListener { _, _, position, _ ->
            selectedIndex = position
        }

        binding.btnAdd.setOnClickListener { addInvites() }
        binding.btnApprove.setOnClickListener { selectedAction("approve") }
        binding.btnReject.setOnClickListener { selectedAction("reject") }
        binding.btnResend.setOnClickListener { selectedAction("resend") }
        binding.btnRemove.setOnClickListener {
            confirmSelectedAction("remove", "Remover este participante?")
        }
        binding.btnRefresh.setOnClickListener { refresh() }

        refresh()
    }

    private fun selectedInvite(): InviteInfo? = invites.getOrNull(selectedIndex)

    private fun refresh() {
        setBusy(true)
        executor.execute {
            try {
                val loaded = api.roomInvites(roomId)
                runOnUiThread {
                    invites = loaded
                    selectedIndex = -1
                    binding.listInvites.adapter = ArrayAdapter(
                        this,
                        android.R.layout.simple_list_item_single_choice,
                        loaded.map {
                            val who = if (it.displayName.isBlank()) it.email else it.displayName + " · " + it.email
                            who + "\n" + it.status
                        }
                    )
                    binding.listInvites.clearChoices()
                    setBusy(false)
                }
            } catch (e: Exception) {
                runOnUiThread {
                    setBusy(false)
                    toast("Erro: " + e.message)
                }
            }
        }
    }

    private fun addInvites() {
        val raw = binding.edtEmails.text.toString().trim()
        if (raw.isBlank()) return
        val emails = raw
            .replace(';', ',')
            .split(',')
            .map { it.trim() }
            .filter { it.isNotBlank() }
            .distinct()

        if (emails.isEmpty()) return

        setBusy(true)
        executor.execute {
            try {
                api.addInvites(roomId, emails)
                runOnUiThread {
                    binding.edtEmails.text?.clear()
                    refresh()
                }
            } catch (e: Exception) {
                runOnUiThread {
                    setBusy(false)
                    toast("Falha ao adicionar: " + e.message)
                }
            }
        }
    }

    private fun selectedAction(action: String) {
        val invite = selectedInvite() ?: run {
            toast("Selecione um convidado.")
            return
        }

        setBusy(true)
        executor.execute {
            try {
                api.inviteAction(roomId, invite.id, action)
                runOnUiThread { refresh() }
            } catch (e: Exception) {
                runOnUiThread {
                    setBusy(false)
                    toast("Falha: " + e.message)
                }
            }
        }
    }

    private fun confirmSelectedAction(action: String, message: String) {
        if (selectedInvite() == null) {
            toast("Selecione um convidado.")
            return
        }

        AlertDialog.Builder(this)
            .setMessage(message)
            .setPositiveButton("Sim") { _, _ -> selectedAction(action) }
            .setNegativeButton("Não", null)
            .show()
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
