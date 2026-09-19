package br.com.maurinsoft.salareuniao

import android.content.Context

class SessionStore(context: Context) {
    private val prefs = context.getSharedPreferences("salareuniao", Context.MODE_PRIVATE)

    var apiBase: String
        get() = prefs.getString("api_base", "https://meet.seu-dominio.example/api/v1") ?: ""
        set(value) = prefs.edit().putString("api_base", value.trimEnd('/')).apply()

    var webBase: String
        get() = prefs.getString("web_base", "https://meet.seu-dominio.example") ?: ""
        set(value) = prefs.edit().putString("web_base", value.trimEnd('/')).apply()

    var email: String
        get() = prefs.getString("email", "") ?: ""
        set(value) = prefs.edit().putString("email", value.trim()).apply()

    var userName: String
        get() = prefs.getString("user_name", "") ?: ""
        set(value) = prefs.edit().putString("user_name", value).apply()

    var userRole: String
        get() = prefs.getString("user_role", "user") ?: "user"
        set(value) = prefs.edit().putString("user_role", value).apply()

    var lastUpdateOfferedCode: Int
        get() = prefs.getInt("last_update_offered_code", 0)
        set(value) = prefs.edit().putInt("last_update_offered_code", value).apply()
}
