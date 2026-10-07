# Integração com as APIs REST · Sala Reunião Desktop

Este documento descreve detalhadamente o contrato de dados e os endpoints da API REST (`/api/v1/`) consumidos pelo cliente desktop em Lazarus.

---

## 1. Padrões Gerais da API

- **Formato:** JSON (`application/json; charset=utf-8`).
- **Autenticação:** Header HTTP `Authorization: Bearer <token>` em todas as rotas protegidas.
- **Estrutura de Resposta Padrão:**
  - Sucesso: `{"ok": true, ...}`
  - Erro: `{"ok": false, "error": "<codigo_do_erro>"}` (acompanhado do HTTP Status Code correspondente: 400, 401, 403, 404, 422, 500).

---

## 2. Endpoints de Autenticação

### 2.1. Login do Usuário
- **Rota:** `POST /api/v1/auth/login.php`
- **Autenticação:** Pública.
- **Request Body:**
  ```json
  {
    "email": "usuario@empresa.com",
    "password": "senha_do_usuario",
    "client_name": "Sala Reunião Desktop"
  }
  ```
- **Response (200 OK):**
  ```json
  {
    "ok": true,
    "token": "a1b2c3d4e5f6...",
    "token_type": "Bearer",
    "expires_at": "2026-11-06 18:00:00",
    "user": {
      "id": 1,
      "name": "Nome do Usuário",
      "email": "usuario@empresa.com",
      "role": "admin"
    }
  }
  ```
- **Erros comuns:** `invalid_credentials` (401).

### 2.2. Perfil do Usuário Logado
- **Rota:** `GET /api/v1/auth/me.php`
- **Header:** `Authorization: Bearer <token>`
- **Response (200 OK):**
  ```json
  {
    "ok": true,
    "user": {
      "id": 1,
      "name": "Nome do Usuário",
      "email": "usuario@empresa.com",
      "role": "admin"
    }
  }
  ```

### 2.3. Logout
- **Rota:** `POST /api/v1/auth/logout.php`
- **Header:** `Authorization: Bearer <token>`
- **Response (200 OK):**
  ```json
  {
    "ok": true
  }
  ```

---

## 3. Endpoints de Gestão de Salas

### 3.1. Listagem de Salas
- **Rota:** `GET /api/v1/rooms.php?scope={mine|all}`
- **Header:** `Authorization: Bearer <token>`
- **Query Params:**
  - `scope=mine`: Salas criadas pelo usuário logado (padrão).
  - `scope=all`: Todas as salas do sistema (disponível apenas para perfil `admin`).
- **Response (200 OK):**
  ```json
  {
    "ok": true,
    "rooms": [
      {
        "id": 42,
        "name": "Reunião de Alinhamento Técnico",
        "description": "Discussão de arquitetura",
        "status": "scheduled",
        "starts_at": "2026-10-08 14:00:00",
        "ends_at": null,
        "invite_count": 5,
        "online_count": 0,
        "owner_user_id": 1,
        "owner_name": "Administrador"
      }
    ]
  }
  ```

### 3.2. Criação de Sala
- **Rota:** `POST /api/v1/rooms.php`
- **Header:** `Authorization: Bearer <token>`
- **Request Body:**
  ```json
  {
    "name": "Reunião de Planejamento",
    "description": "Pauta de sprints",
    "starts_at": "2026-10-08 10:00:00"
  }
  ```
- **Response (201 Created):**
  ```json
  {
    "ok": true,
    "room": {
      "id": 43,
      "name": "Reunião de Planejamento",
      "description": "Pauta de sprints",
      "starts_at": "2026-10-08 10:00:00",
      "status": "scheduled",
      "host_join_token": "f0e1d2c3b4a5..."
    }
  }
  ```

### 3.3. Detalhes e Participantes da Sala
- **Rota:** `GET /api/v1/room.php?id={roomId}`
- **Header:** `Authorization: Bearer <token>`
- **Response (200 OK):**
  ```json
  {
    "ok": true,
    "room": {
      "id": 42,
      "name": "Reunião de Alinhamento Técnico",
      "description": "Discussão de arquitetura",
      "status": "scheduled",
      "starts_at": "2026-10-08 14:00:00",
      "ends_at": null
    },
    "participants": [
      {
        "id": 101,
        "email": "convidado@empresa.com",
        "display_name": "Carlos Silva",
        "status": "approved",
        "created_at": "2026-10-07 10:00:00"
      }
    ],
    "host_join_token": "a9b8c7d6e5..."
  }
  ```

### 3.4. Ações na Sala (Abrir, Encerrar, Cancelar)
- **Rota:** `POST /api/v1/room.php?id={roomId}`
- **Header:** `Authorization: Bearer <token>`
- **Request Body:**
  ```json
  {
    "action": "open"
  }
  ```
  *(Ações válidas: `open`, `close`, `cancel`, `update`)*
- **Response (200 OK):**
  ```json
  {
    "ok": true
  }
  ```

---

## 4. Endpoints de Convites (`room_invites.php`)

### 4.1. Listar Convites da Sala
- **Rota:** `GET /api/v1/room_invites.php?room_id={roomId}`
- **Header:** `Authorization: Bearer <token>`
- **Response (200 OK):**
  ```json
  {
    "ok": true,
    "invites": [
      {
        "id": 101,
        "email": "convidado@empresa.com",
        "display_name": "Carlos Silva",
        "status": "approved",
        "token": "tok_xyz...",
        "participant_key": "part_123...",
        "created_at": "2026-10-07 10:00:00"
      }
    ]
  }
  ```

### 4.2. Criar e Enviar Novo Convite
- **Rota:** `POST /api/v1/room_invites.php`
- **Header:** `Authorization: Bearer <token>`
- **Request Body:**
  ```json
  {
    "room_id": 42,
    "email": "novo.convidado@empresa.com",
    "display_name": "Mariana Souza",
    "send_mail": true
  }
  ```
- **Response (201 Created):**
  ```json
  {
    "ok": true,
    "invite": {
      "id": 102,
      "room_id": 42,
      "email": "novo.convidado@empresa.com",
      "display_name": "Mariana Souza",
      "status": "waiting",
      "token": "token_individual_gerado",
      "join_url": "https://servidor/salareuniao/join.php?token=token_individual_gerado"
    }
  }
  ```

---

## 5. Agenda de Reuniões

- **Rota:** `GET /api/v1/agenda.php?days={n}&scope={mine|all}`
- **Header:** `Authorization: Bearer <token>`
- **Query Params:**
  - `days`: Quantidade de dias futuros para busca (padrão: 30).
  - `scope`: `mine` ou `all`.
- **Response (200 OK):**
  ```json
  {
    "ok": true,
    "agenda": [
      {
        "id": 42,
        "name": "Reunião de Alinhamento Técnico",
        "starts_at": "2026-10-08 14:00:00",
        "ends_at": null,
        "status": "scheduled",
        "description": "Discussão de arquitetura"
      }
    ]
  }
  ```

---

## 6. Verificação de Atualizações (`desktop_update.php`)

- **Rota:** `GET /api/v1/desktop_update.php?platform=windows`
- **Header:** `Authorization: Bearer <token>`
- **Response (200 OK):**
  ```json
  {
    "ok": true,
    "enabled": true,
    "version": "1.2.0",
    "required": false,
    "notes": "- Melhorias no suporte a CEF4Delphi\n- Correções de reconexão",
    "url": "https://servidor/salareuniao/downloads/SalaReuniaoSetup_v1.2.exe",
    "platform": "windows"
  }
  ```
