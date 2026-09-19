package br.com.maurinsoft.salareuniao

data class UserInfo(
    val id: Long,
    val name: String,
    val email: String,
    val role: String
)

data class RoomInfo(
    val id: Long,
    val name: String,
    val description: String,
    val startsAt: String,
    val status: String,
    val inviteCount: Int,
    val onlineCount: Int
)

data class AgendaInfo(
    val id: Long,
    val name: String,
    val startsAt: String,
    val status: String
)

data class LoginResult(
    val token: String,
    val user: UserInfo
)
