package br.com.maurinsoft.salareuniao

import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedReader
import java.net.HttpURLConnection
import java.net.URL

class ApiException(message: String) : Exception(message)

class ApiClient(
    var baseUrl: String,
    var token: String? = null
) {
    private fun request(method: String, path: String, body: JSONObject? = null): JSONObject {
        val url = URL(baseUrl.trimEnd('/') + "/" + path.trimStart('/'))
        val con = (url.openConnection() as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = 12000
            readTimeout = 20000
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Content-Type", "application/json; charset=utf-8")
            setRequestProperty("User-Agent", "SalaReuniaoAndroid/1.0.0")
            token?.takeIf { it.isNotBlank() }?.let {
                setRequestProperty("Authorization", "Bearer $it")
            }
            if (body != null) doOutput = true
        }

        if (body != null) {
            con.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
        }

        val code = con.responseCode
        val stream = if (code in 200..299) con.inputStream else con.errorStream
        val text = stream?.bufferedReader()?.use(BufferedReader::readText).orEmpty()
        con.disconnect()

        val json = if (text.isBlank()) JSONObject() else JSONObject(text)
        if (code !in 200..299) {
            throw ApiException(json.optString("error", "HTTP $code"))
        }
        return json
    }

    fun login(email: String, password: String): LoginResult {
        val body = JSONObject()
            .put("email", email)
            .put("password", password)
            .put("client_name", "Android")
        val json = request("POST", "auth/login.php", body)
        val user = json.getJSONObject("user")
        return LoginResult(
            json.getString("token"),
            UserInfo(
                user.getLong("id"),
                user.getString("name"),
                user.getString("email"),
                user.getString("role")
            )
        )
    }

    fun me(): UserInfo {
        val user = request("GET", "auth/me.php").getJSONObject("user")
        return UserInfo(user.getLong("id"), user.getString("name"), user.getString("email"), user.getString("role"))
    }

    fun logout() {
        request("POST", "auth/logout.php")
    }

    fun rooms(): List<RoomInfo> {
        val arr = request("GET", "rooms.php?scope=mine").getJSONArray("rooms")
        return (0 until arr.length()).map { i ->
            val o = arr.getJSONObject(i)
            RoomInfo(
                o.getLong("id"),
                o.optString("name"),
                o.optString("description"),
                o.optString("starts_at"),
                o.optString("status"),
                o.optInt("invite_count"),
                o.optInt("online_count")
            )
        }
    }

    fun agenda(days: Int = 30): List<AgendaInfo> {
        val arr = request("GET", "agenda.php?days=$days&scope=mine").getJSONArray("agenda")
        return (0 until arr.length()).map { i ->
            val o = arr.getJSONObject(i)
            AgendaInfo(o.getLong("id"), o.optString("name"), o.optString("starts_at"), o.optString("status"))
        }
    }

    fun createRoom(name: String, description: String, startsAt: String?): Long {
        val body = JSONObject().put("name", name).put("description", description)
        if (!startsAt.isNullOrBlank()) body.put("starts_at", startsAt)
        return request("POST", "rooms.php", body).getJSONObject("room").getLong("id")
    }

    fun roomAction(id: Long, action: String) {
        request("POST", "room.php?id=$id", JSONObject().put("action", action))
    }

    fun hostJoinToken(id: Long): String {
        return request("GET", "room.php?id=$id").optString("host_join_token")
    }
}
